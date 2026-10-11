//! `tenma --inspect`: a debugger over the Debug Adapter Protocol (DAP), the protocol of Neovim
//! (nvim-dap), Zed, Helix and VS Code. Messages are JSON with a Content-Length header over TCP.
//!
//! The DAP server is a thread of its own. PHP calls `tenma_rs_statement` before every statement of
//! the script's code; at a breakpoint or after a step the worker thread stops there and blocks
//! until the editor continues. While stopped, it answers what needs PHP (threads, stacks,
//! variables) itself, the server passes those requests on. A stopped worker stops everything it
//! runs, other requests and timers included, like Node.
//!
//! DAP threads are fibers: every request, timer and `Tenma::async()` task is one, with the stack
//! it waits in.

use std::cell::Cell;
use std::collections::{BTreeSet, HashMap};
use std::io::{BufRead, BufReader, Write};
use std::net::{SocketAddr, TcpListener, TcpStream};
use std::os::unix::ffi::OsStrExt;
use std::sync::atomic::{AtomicBool, AtomicU8, AtomicU64, Ordering};
use std::sync::{Condvar, LazyLock, Mutex, mpsc};

use serde_json::{Value, json};

use crate::php::bytes;

unsafe extern "C" {
    fn tenma_set_inspect();
    fn tenma_inspect_thread();
    fn tenma_inspect_thread_id() -> u32;
    fn tenma_inspect_depth() -> u32;
    fn tenma_inspect_threads(len: *mut usize) -> *mut u8;
    fn tenma_inspect_frames(thread: u32, len: *mut usize) -> *mut u8;
    fn tenma_inspect_locals(thread: u32, index: u32, len: *mut usize) -> *mut u8;
    fn tenma_inspect_children(reference: u32, len: *mut usize) -> *mut u8;
    fn tenma_inspect_resume();
}

/// The address without --inspect=…: only this machine, a debugger can run any code.
pub const DEFAULT: SocketAddr = SocketAddr::new(std::net::IpAddr::V4(std::net::Ipv4Addr::LOCALHOST), 9229);

/// A frame's ID is its thread times this plus its position in the stack.
const FRAMES_PER_THREAD: u64 = 10_000;
/// Variable references from here on are a frame's locals (frame ID added), below it arrays and
/// objects to expand.
const LOCALS: u64 = 1 << 32;

/// Breakpoints by file (canonical path), from setBreakpoints.
static BREAKPOINTS: LazyLock<Mutex<HashMap<Vec<u8>, BTreeSet<u32>>>> = LazyLock::new(Default::default);
/// Exceptions to stop at, from setExceptionBreakpoints.
static EXCEPTIONS: AtomicU8 = AtomicU8::new(UNCAUGHT);
const UNCAUGHT: u8 = 1;
const ALL: u8 = 2;
/// The editor pressed pause: stop at the next statement.
static PAUSE: AtomicBool = AtomicBool::new(false);
/// --inspect-brk: stop at the script's first statement.
static ENTRY: AtomicBool = AtomicBool::new(false);
/// A worker is stopped and waits for commands. Only the server sets it back, when it resumes the
/// worker: a second continue in quick succession must not resume the next stop.
static STOPPED: AtomicBool = AtomicBool::new(false);
/// tenma is shutting down: nothing stops any more, workers must finish.
static ENDING: AtomicBool = AtomicBool::new(false);
/// --inspect-brk waits for this: the editor has set its breakpoints (configurationDone).
static CONFIGURED: (Mutex<bool>, Condvar) = (Mutex::new(false), Condvar::new());
/// The server's commands to the stopped worker.
static COMMANDS: LazyLock<(Mutex<mpsc::Sender<Command>>, Mutex<mpsc::Receiver<Command>>)> = LazyLock::new(|| {
    let (sender, receiver) = mpsc::channel();
    (Mutex::new(sender), Mutex::new(receiver))
});
/// The editor's connection for writing; the worker writes its answers and events there too.
static CLIENT: Mutex<Option<TcpStream>> = Mutex::new(None);
static SEQ: AtomicU64 = AtomicU64::new(1);

enum Command {
    /// A request only the stopped worker can answer (threads, stacks, variables).
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

/// After `php::thread_start` in a worker: the script's code stops the debugger. A step from the
/// previous interpreter (--watch) does not carry over.
pub fn thread() {
    PREVIOUS.set((0, 0));
    STEPPING.set(None);
    unsafe { tenma_inspect_thread() }
}

/// Starts the DAP server. `wait` (--inspect-brk): returns once an editor has connected and set its
/// breakpoints, and the script stops at its first statement.
pub fn listen(address: SocketAddr, wait: bool) -> Result<(), String> {
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
    if wait {
        eprintln!("tenma: waiting for the debugger");
        let mut configured = CONFIGURED.0.lock().unwrap();
        while !*configured {
            configured = CONFIGURED.1.wait(configured).unwrap();
        }
        ENTRY.store(true, Ordering::Relaxed);
    }
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
    EXCEPTIONS.store(UNCAUGHT, Ordering::Relaxed);
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
            let filters = json!([
                {"filter": "uncaught", "label": "Uncaught exceptions", "default": true},
                {"filter": "all", "label": "All exceptions", "default": false},
            ]);
            respond(request, json!({"supportsConfigurationDoneRequest": true, "exceptionBreakpointFilters": filters}));
            event("initialized", json!({}));
        }
        "launch" | "attach" => respond(request, json!({})),
        "configurationDone" => {
            respond(request, json!({}));
            *CONFIGURED.0.lock().unwrap() = true;
            CONFIGURED.1.notify_all();
        }
        "setExceptionBreakpoints" => {
            let filters = arguments["filters"].as_array().into_iter().flatten().filter_map(Value::as_str);
            EXCEPTIONS.store(filters.fold(0, |bits, filter| bits | if filter == "all" { ALL } else { UNCAUGHT }), Ordering::Relaxed);
            respond(request, json!({}));
        }
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
        "scopes" => {
            let frame = arguments["frameId"].as_u64().unwrap_or_default();
            respond(request, json!({"scopes": [{"name": "Locals", "presentationHint": "locals", "variablesReference": LOCALS + frame, "expensive": false}]}));
        }
        "threads" | "stackTrace" | "variables" => {
            if STOPPED.load(Ordering::Relaxed) {
                let _ = COMMANDS.0.lock().unwrap().send(Command::Answer(request.clone()));
            } else if request["command"] == "threads" {
                // Running: the fibers can only be read while the worker stands still
                respond(request, json!({"threads": [{"id": 1, "name": "main"}]}));
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
    let reason = if ENTRY.swap(false, Ordering::Relaxed) {
        "entry"
    } else if PAUSE.swap(false, Ordering::Relaxed) {
        "pause"
    } else if STEPPING.get().is_some_and(|(step, since, depth)| since == fiber && arrived(step, depth)) {
        "step"
    } else if BREAKPOINTS.lock().unwrap().get(bytes(file, file_len)).is_some_and(|lines| lines.contains(&line)) {
        "breakpoint"
    } else {
        return;
    };
    STEPPING.set(None);
    let step = stop(json!({"reason": reason}));
    STEPPING.set(step.map(|step| (step, fiber, unsafe { tenma_inspect_depth() })));
}

/// PHP throws an exception that the app's code catches or not (`uncaught`); the C layer leaves out
/// those the stdlib catches itself.
#[unsafe(no_mangle)]
extern "C" fn tenma_rs_exception(description: *const u8, description_len: usize, uncaught: bool) {
    let filters = EXCEPTIONS.load(Ordering::Relaxed);
    if ENDING.load(Ordering::Relaxed) || filters & ALL == 0 && !(uncaught && filters & UNCAUGHT != 0) {
        return;
    }
    let description = String::from_utf8_lossy(bytes(description, description_len)).into_owned();
    let text = if uncaught { "Uncaught exception" } else { "Exception" };
    // ponytail: a step from here acts as continue; stepping into the catch block needs the fiber and depth
    let _ = stop(json!({"reason": "exception", "description": description, "text": text}));
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
fn stop(mut body: Value) -> Option<Step> {
    STOPPED.store(true, Ordering::Relaxed);
    body["threadId"] = json!(unsafe { tenma_inspect_thread_id() });
    body["allThreadsStopped"] = json!(true);
    event("stopped", body);
    let commands = COMMANDS.1.lock().unwrap();
    let step = loop {
        match commands.recv() {
            Ok(Command::Answer(request)) => answer(&request),
            Ok(Command::Resume(step)) => break step,
            Err(_) => break None,
        }
    };
    unsafe { tenma_inspect_resume() };
    step
}

/// Answers on the stopped worker, where PHP's fibers and frames can be read.
fn answer(request: &Value) {
    let arguments = &request["arguments"];
    match request["command"].as_str() {
        Some("threads") => {
            let threads: Vec<Value> = fields(|len| unsafe { tenma_inspect_threads(len) })
                .chunks_exact(2)
                .map(|thread| json!({"id": thread[0].parse::<u64>().unwrap_or_default(), "name": thread[1]}))
                .collect();
            respond(request, json!({"threads": threads}));
        }
        Some("stackTrace") => {
            let thread = arguments["threadId"].as_u64().unwrap_or_default();
            let frames: Vec<Value> = fields(|len| unsafe { tenma_inspect_frames(u32::try_from(thread).unwrap_or_default(), len) })
                .chunks_exact(3)
                .take(FRAMES_PER_THREAD as usize)
                .enumerate()
                .map(|(index, frame)| {
                    let name = frame[0].rsplit('/').next().unwrap_or_default();
                    let line: u64 = frame[1].parse().unwrap_or_default();
                    // Closures are called {closure:/full/path.php:8}
                    let function = frame[2].replace(&frame[0], name);
                    json!({"id": thread * FRAMES_PER_THREAD + index as u64, "name": function, "source": {"name": name, "path": frame[0]}, "line": line, "column": 1})
                })
                .collect();
            respond(request, json!({"stackFrames": frames, "totalFrames": frames.len()}));
        }
        Some("variables") => {
            let reference = arguments["variablesReference"].as_u64().unwrap_or_default();
            let packed = if reference >= LOCALS {
                let frame = reference - LOCALS;
                let (thread, index) = (frame / FRAMES_PER_THREAD, frame % FRAMES_PER_THREAD);
                fields(|len| unsafe { tenma_inspect_locals(u32::try_from(thread).unwrap_or_default(), index as u32, len) })
            } else {
                fields(|len| unsafe { tenma_inspect_children(reference as u32, len) })
            };
            let variables: Vec<Value> = packed
                .chunks_exact(4)
                .map(|variable| json!({"name": variable[0], "value": variable[1], "type": variable[2], "variablesReference": variable[3].parse::<u64>().unwrap_or_default()}))
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
