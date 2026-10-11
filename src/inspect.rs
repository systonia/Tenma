//! `tenma --inspect`: a debugger over the Debug Adapter Protocol (DAP), the protocol of Neovim
//! (nvim-dap), Zed, Helix and VS Code. Messages are JSON with a Content-Length header over TCP.
//!
//! The DAP server is a thread of its own. PHP calls `tenma_rs_statement` before every statement of
//! the script's code; at a breakpoint or after a step the worker thread stops there and blocks
//! until the editor continues. While stopped, it answers what needs PHP (stack, variables) itself,
//! the server passes those requests on. A stopped worker stops everything it runs, other requests
//! and timers included, like Node.

use std::cell::Cell;
use std::collections::{BTreeSet, HashMap};
use std::io::{BufRead, BufReader, Write};
use std::net::{SocketAddr, TcpListener, TcpStream};
use std::os::unix::ffi::OsStrExt;
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{LazyLock, Mutex, mpsc};

use serde_json::{Value, json};

use crate::php::bytes;

unsafe extern "C" {
    fn tenma_set_inspect();
    fn tenma_inspect_thread();
    fn tenma_inspect_depth() -> u32;
    fn tenma_inspect_frames(len: *mut usize) -> *mut u8;
    fn tenma_inspect_variables(index: u32, len: *mut usize) -> *mut u8;
}

/// The address without --inspect=…: only this machine, a debugger can run any code.
pub const DEFAULT: SocketAddr = SocketAddr::new(std::net::IpAddr::V4(std::net::Ipv4Addr::LOCALHOST), 9229);

/// DAP knows threads; until fibers show as threads of their own there is one.
const THREAD: u64 = 1;

/// Breakpoints by file (canonical path), from setBreakpoints.
static BREAKPOINTS: LazyLock<Mutex<HashMap<Vec<u8>, BTreeSet<u32>>>> = LazyLock::new(Default::default);
/// The editor pressed pause: stop at the next statement.
static PAUSE: AtomicBool = AtomicBool::new(false);
/// A worker is stopped and waits for commands. Only the server sets it back, when it resumes the
/// worker: a second continue in quick succession must not resume the next stop.
static STOPPED: AtomicBool = AtomicBool::new(false);
/// tenma is shutting down: nothing stops any more, workers must finish.
static ENDING: AtomicBool = AtomicBool::new(false);
/// The server's commands to the stopped worker.
static COMMANDS: LazyLock<(Mutex<mpsc::Sender<Command>>, Mutex<mpsc::Receiver<Command>>)> = LazyLock::new(|| {
    let (sender, receiver) = mpsc::channel();
    (Mutex::new(sender), Mutex::new(receiver))
});
/// The editor's connection for writing; the worker writes its answers and events there too.
static CLIENT: Mutex<Option<TcpStream>> = Mutex::new(None);
static SEQ: AtomicU64 = AtomicU64::new(1);

enum Command {
    /// A request only the stopped worker can answer (stack, variables).
    Answer(Value),
    /// Run on; with a step, stop again after it.
    Resume(Option<Step>),
}

#[derive(Clone, Copy, PartialEq)]
enum Step {
    Over,
    In,
    Out,
}

thread_local! {
    /// The last statement's frame and line: a line with several statements stops once.
    static PREVIOUS: Cell<(usize, u32)> = const { Cell::new((0, 0)) };
    /// The step under way: in which fiber, from which depth.
    static STEPPING: Cell<Option<(Step, usize, u32)>> = const { Cell::new(None) };
}

/// Before `php::start`: PHP gets the debugger's extension and runs without opcache.
pub fn configure() {
    unsafe { tenma_set_inspect() }
}

/// After `php::thread_start` in a worker: the script's code stops the debugger.
pub fn thread() {
    unsafe { tenma_inspect_thread() }
}

/// Starts the DAP server.
pub fn listen(address: SocketAddr) -> Result<(), String> {
    let listener = TcpListener::bind(address).map_err(|error| format!("--inspect: {address} not available: {error}"))?;
    if !address.ip().is_loopback() {
        eprintln!("tenma: warning: the debugger on {address} is open to the network, anyone who reaches it can run code");
    }
    eprintln!("tenma: debugger (DAP) listening on {address}");
    std::thread::spawn(move || {
        // One editor at a time; the next waits until it disconnects
        for stream in listener.incoming().flatten() {
            session(stream);
        }
    });
    Ok(())
}

/// tenma shuts down: a stopped worker runs on, and none stops again.
pub fn release() {
    ENDING.store(true, Ordering::Relaxed);
    detach();
}

/// The editor is gone: its breakpoints go too, a stopped worker runs on.
fn detach() {
    BREAKPOINTS.lock().unwrap().clear();
    PAUSE.store(false, Ordering::Relaxed);
    resume(None);
}

fn resume(step: Option<Step>) {
    if STOPPED.swap(false, Ordering::Relaxed) {
        let _ = COMMANDS.0.lock().unwrap().send(Command::Resume(step));
    }
}

fn session(stream: TcpStream) {
    let Ok(writer) = stream.try_clone() else {
        return;
    };
    *CLIENT.lock().unwrap() = Some(writer);
    let mut reader = BufReader::new(stream);
    while let Some(message) = read_message(&mut reader) {
        if !handle(&message) {
            break;
        }
    }
    *CLIENT.lock().unwrap() = None;
    detach();
}

/// The next message: headers up to an empty line, then Content-Length bytes of JSON.
fn read_message(reader: &mut impl BufRead) -> Option<Value> {
    let mut length = None;
    loop {
        let mut line = String::new();
        if reader.read_line(&mut line).ok()? == 0 {
            return None;
        }
        let line = line.trim_end();
        if line.is_empty() {
            break;
        }
        if let Some((name, value)) = line.split_once(':')
            && name.eq_ignore_ascii_case("content-length")
        {
            length = value.trim().parse().ok();
        }
    }
    let mut body = vec![0; length?];
    reader.read_exact(&mut body).ok()?;
    serde_json::from_slice(&body).ok()
}

fn send(mut message: Value) {
    message["seq"] = json!(SEQ.fetch_add(1, Ordering::Relaxed));
    let body = message.to_string();
    if let Some(client) = CLIENT.lock().unwrap().as_mut() {
        let _ = write!(client, "Content-Length: {}\r\n\r\n{body}", body.len());
    }
}

fn respond(request: &Value, body: Value) {
    send(json!({"type": "response", "request_seq": request["seq"], "command": request["command"], "success": true, "body": body}));
}

fn fail(request: &Value, message: &str) {
    send(json!({"type": "response", "request_seq": request["seq"], "command": request["command"], "success": false, "message": message}));
}

fn event(name: &str, body: Value) {
    send(json!({"type": "event", "event": name, "body": body}));
}

/// Handles a request on the server thread; false after disconnect.
fn handle(request: &Value) -> bool {
    let arguments = &request["arguments"];
    match request["command"].as_str().unwrap_or_default() {
        "initialize" => {
            respond(request, json!({"supportsConfigurationDoneRequest": true}));
            event("initialized", json!({}));
        }
        "launch" | "attach" | "configurationDone" | "setExceptionBreakpoints" => respond(request, json!({})),
        "setBreakpoints" => {
            let path = arguments["source"]["path"].as_str().unwrap_or_default();
            // PHP knows files by their real path (on macOS /tmp is /private/tmp)
            let file = std::fs::canonicalize(path).map_or_else(|_| path.as_bytes().to_vec(), |path| path.as_os_str().as_bytes().to_vec());
            let lines: BTreeSet<u32> = arguments["breakpoints"].as_array().into_iter().flatten().filter_map(|breakpoint| breakpoint["line"].as_u64()).filter_map(|line| u32::try_from(line).ok()).collect();
            let verified: Vec<Value> = lines.iter().map(|line| json!({"verified": true, "line": line})).collect();
            let mut breakpoints = BREAKPOINTS.lock().unwrap();
            if lines.is_empty() {
                breakpoints.remove(&file);
            } else {
                breakpoints.insert(file, lines);
            }
            drop(breakpoints);
            respond(request, json!({"breakpoints": verified}));
        }
        "threads" => respond(request, json!({"threads": [{"id": THREAD, "name": "main"}]})),
        // Frame IDs count from 0, variable references from 1 (0 means none)
        "scopes" => {
            let frame = arguments["frameId"].as_u64().unwrap_or_default();
            respond(request, json!({"scopes": [{"name": "Locals", "presentationHint": "locals", "variablesReference": frame + 1, "expensive": false}]}));
        }
        "stackTrace" | "variables" => {
            if STOPPED.load(Ordering::Relaxed) {
                let _ = COMMANDS.0.lock().unwrap().send(Command::Answer(request.clone()));
            } else {
                fail(request, "not stopped");
            }
        }
        "continue" => {
            respond(request, json!({"allThreadsContinued": true}));
            resume(None);
        }
        "next" => {
            respond(request, json!({}));
            resume(Some(Step::Over));
        }
        "stepIn" => {
            respond(request, json!({}));
            resume(Some(Step::In));
        }
        "stepOut" => {
            respond(request, json!({}));
            resume(Some(Step::Out));
        }
        "pause" => {
            PAUSE.store(true, Ordering::Relaxed);
            respond(request, json!({}));
        }
        "disconnect" => {
            respond(request, json!({}));
            return false;
        }
        command => fail(request, &format!("{command} is not supported")),
    }
    true
}

/// PHP is about to run a statement: stops here at a breakpoint, after a step or on pause.
/// `frame` and `fiber` identify the call and the fiber (0 outside fibers).
#[unsafe(no_mangle)]
extern "C" fn tenma_rs_statement(file: *const u8, file_len: usize, line: u32, frame: usize, fiber: usize) {
    if ENDING.load(Ordering::Relaxed) || PREVIOUS.replace((frame, line)) == (frame, line) {
        return;
    }
    let reason = if PAUSE.swap(false, Ordering::Relaxed) {
        "pause"
    } else if STEPPING.get().is_some_and(|(step, since, depth)| since == fiber && arrived(step, depth)) {
        "step"
    } else if BREAKPOINTS.lock().unwrap().get(bytes(file, file_len)).is_some_and(|lines| lines.contains(&line)) {
        "breakpoint"
    } else {
        return;
    };
    STEPPING.set(None);
    let step = stop(reason);
    STEPPING.set(step.map(|step| (step, fiber, unsafe { tenma_inspect_depth() })));
}

/// Has a step that began at `depth` arrived at the current statement?
fn arrived(step: Step, depth: u32) -> bool {
    let now = unsafe { tenma_inspect_depth() };
    match step {
        Step::In => true,
        Step::Over => now <= depth,
        Step::Out => now < depth,
    }
}

/// Blocks the worker until the editor resumes it; returns the step it asked for.
fn stop(reason: &str) -> Option<Step> {
    STOPPED.store(true, Ordering::Relaxed);
    event("stopped", json!({"reason": reason, "threadId": THREAD, "allThreadsStopped": true}));
    let commands = COMMANDS.1.lock().unwrap();
    loop {
        match commands.recv() {
            Ok(Command::Answer(request)) => answer(&request),
            Ok(Command::Resume(step)) => return step,
            Err(_) => return None,
        }
    }
}

/// Answers on the stopped worker, where PHP's frames can be read.
fn answer(request: &Value) {
    match request["command"].as_str() {
        Some("stackTrace") => {
            let frames: Vec<Value> = fields(|len| unsafe { tenma_inspect_frames(len) })
                .chunks_exact(3)
                .enumerate()
                .map(|(id, frame)| {
                    let name = frame[0].rsplit('/').next().unwrap_or_default();
                    let line: u64 = frame[1].parse().unwrap_or_default();
                    // Closures are called {closure:/full/path.php:8}
                    let function = frame[2].replace(&frame[0], name);
                    json!({"id": id, "name": function, "source": {"name": name, "path": frame[0]}, "line": line, "column": 1})
                })
                .collect();
            respond(request, json!({"stackFrames": frames, "totalFrames": frames.len()}));
        }
        Some("variables") => {
            let frame = request["arguments"]["variablesReference"].as_u64().unwrap_or_default().saturating_sub(1);
            let variables: Vec<Value> = fields(|len| unsafe { tenma_inspect_variables(u32::try_from(frame).unwrap_or(u32::MAX), len) })
                .chunks_exact(3)
                .map(|variable| json!({"name": variable[0], "value": variable[1], "type": variable[2], "variablesReference": 0}))
                .collect();
            respond(request, json!({"variables": variables}));
        }
        _ => {}
    }
}

/// The null-terminated fields C packed into malloc() memory; freed here.
fn fields(read: impl FnOnce(*mut usize) -> *mut u8) -> Vec<String> {
    let mut len = 0;
    let packed = read(&mut len);
    let fields = bytes(packed, len).split(|&byte| byte == 0).map(|field| String::from_utf8_lossy(field).into_owned()).collect();
    unsafe { libc::free(packed.cast()) };
    fields
}
