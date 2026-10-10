//! Tenma: PHP with Node's execution model. Each worker is a thread with its own PHP interpreter
//! and its own event loop; the script runs once per worker, then the registered servers handle
//! requests until Ctrl+C. The main thread accepts connections and hands them to the workers
//! round-robin.
//!
//! A fatal PHP error only breaks the interpreter of one worker: the worker restarts it and runs
//! the script again. Ctrl+C or SIGTERM stop accepting connections and let running requests
//! finish.

mod db;
mod files;
mod fs;
mod ops;
mod php;
mod streams;

use std::cell::Cell;
use std::collections::{BTreeSet, HashMap};
use std::convert::Infallible;
use std::process::ExitCode;
use std::rc::Rc;
use std::sync::mpsc;
use std::thread::JoinHandle;
use std::time::Duration;

use bytes::Bytes;
use http_body_util::{BodyExt, Either, Full, Limited};
use hyper::body::Incoming;
use hyper::server::conn::http1;
use hyper::service::service_fn;
use hyper::{Request, Response, StatusCode};
use hyper_util::rt::TokioIo;
use tokio::net::{TcpListener, TcpStream};
use tokio::sync::Notify;
use tokio::sync::mpsc::{UnboundedReceiver, UnboundedSender, unbounded_channel};
use tokio::task::LocalSet;

const STDLIB: &str = include_str!("../stdlib/tenma.php");
/// Prints the public API as stubs; only evaluated for `tenma --stubs`.
const STUBS: &str = include_str!("../stdlib/stubs.php");
const USAGE: &str = "Usage: tenma [--workers N|auto] [--max-body SIZE] [--max-requests N] [--db-pool N] [-d name=value] [-c php.ini] [--server-name NAME] [--request-timeout SECONDS] <script.php> [arguments …]\n       tenma --stubs > tenma.php   (the PHP API as stubs for IDEs and PHPStan)";
/// How long running requests may take to finish on shutdown or restart.
const DRAIN: Duration = Duration::from_secs(10);
/// PHP expects the large stack of a main thread, not the 2 MB of a Rust thread.
const WORKER_STACK: usize = 8 * 1024 * 1024;

/// An accepted connection and the port it came in on.
type Connection = (u16, std::net::TcpStream);

/// What a worker reports after running the script: its servers or the exit status.
type Ready = Result<Vec<php::Listener>, u8>;

struct Worker {
    connections: UnboundedSender<Connection>,
    /// Returns the exit status when a script without servers has finished.
    thread: JoinHandle<u8>,
}

#[derive(Clone, Copy)]
struct Options {
    workers: usize,
    /// Largest request body in bytes, 413 above that.
    max_body: usize,
    /// After this many requests a worker restarts its interpreter, against slow leaks.
    max_requests: Option<u64>,
    /// How long the headers and body of a request may take to arrive (against Slowloris).
    request_timeout: Duration,
}

/// Why a worker's event loop ends.
enum Stop {
    /// No more connections: tenma shuts down.
    Closed,
    /// Restart the interpreter and run the script again.
    Restart,
}

thread_local! {
    /// Wakes the worker's event loop so it restarts its interpreter.
    static RESTART: Rc<Notify> = Rc::new(Notify::new());
    /// After a fatal error: this thread's PHP must not run again until the restart.
    static BROKEN: Cell<bool> = const { Cell::new(false) };
    /// Requests since the interpreter last started.
    static HANDLED: Cell<u64> = const { Cell::new(0) };
}

/// This worker's interpreter is unusable after a fatal error: restart it right away.
pub fn interpreter_broken() {
    eprintln!("tenma: fatal PHP error, restarting worker");
    BROKEN.set(true);
    RESTART.with(|restart| restart.notify_one());
}

pub fn broken() -> bool {
    BROKEN.get()
}

fn main() -> ExitCode {
    if std::env::args().nth(1).as_deref() == Some("--stubs") {
        return stubs();
    }
    let (argv, options) = match parse_args() {
        Ok(parsed) => parsed,
        Err(message) => {
            eprintln!("{message}\n{USAGE}");
            return ExitCode::from(2);
        }
    };
    streams::MAX_MESSAGE.store(options.max_body, std::sync::atomic::Ordering::Relaxed);
    if let Err(message) = php::start() {
        eprintln!("tenma: {message}");
        return ExitCode::FAILURE;
    }
    let status = run(&argv, options);
    php::stop();
    ExitCode::from(status)
}

/// `tenma --stubs`: loads the standard library without a script and prints its public API as stubs.
fn stubs() -> ExitCode {
    let version = format!("namespace Tenma\\Internal\\Stubs; const VERSION = '{}';", env!("CARGO_PKG_VERSION"));
    // On the main thread php_embed_init() has already started a request: no thread_start() here
    let printed = php::start().and_then(|()| {
        php::eval(STDLIB.trim_start_matches("<?php"), "tenma:stdlib")
            .and_then(|()| php::eval(&version, "tenma:version"))
            .and_then(|()| php::eval(STUBS.trim_start_matches("<?php"), "tenma:stubs"))
    });
    php::stop();
    match printed {
        Ok(()) => ExitCode::SUCCESS,
        Err(message) => {
            eprintln!("tenma: {message}");
            ExitCode::FAILURE
        }
    }
}

/// Options for tenma, then the script; everything after it belongs to the script ($argv), as with `php script.php …`.
fn parse_args() -> Result<(Vec<String>, Options), String> {
    let mut args = std::env::args().skip(1);
    let mut options = Options { workers: 1, max_body: 10 << 20, max_requests: None, request_timeout: Duration::from_secs(30) };
    let mut argv = Vec::new();
    while let Some(arg) = args.next() {
        let mut value = |name: &str| args.next().ok_or(format!("{name} needs a value"));
        match arg.as_str() {
            "--workers" | "-w" => {
                options.workers = match value("--workers")?.as_str() {
                    "auto" => std::thread::available_parallelism().map_or(1, usize::from),
                    number => number.parse().ok().filter(|&count| count > 0).ok_or(format!("--workers {number}: not a number above 0"))?,
                };
            }
            "--max-body" => options.max_body = parse_size(&value("--max-body")?)?,
            "--db-pool" => {
                let number = value("--db-pool")?;
                let size = number.parse().ok().filter(|&count: &usize| count > 0).ok_or(format!("--db-pool {number}: not a number above 0"))?;
                db::POOL_SIZE.store(size, std::sync::atomic::Ordering::Relaxed);
            }
            // As with php: -d name=value or -dname=value, -c path
            "-d" => php::define_ini(&value("-d")?)?,
            define if define.starts_with("-d") => php::define_ini(&define[2..])?,
            "-c" => php::set_ini_path(&value("-c")?)?,
            "--request-timeout" => {
                let seconds = value("--request-timeout")?;
                options.request_timeout = seconds.parse::<f64>().ok().filter(|&s| s > 0.0).map(Duration::from_secs_f64).ok_or(format!("--request-timeout {seconds}: not a number above 0"))?;
            }
            "--server-name" => php::set_server_name(&value("--server-name")?)?,
            "--max-requests" => {
                let number = value("--max-requests")?;
                options.max_requests = Some(number.parse().ok().filter(|&count| count > 0).ok_or(format!("--max-requests {number}: not a number above 0"))?);
            }
            option if option.starts_with('-') => return Err(format!("Unknown option: {option}")),
            _ => {
                argv.push(arg);
                argv.extend(args);
                break;
            }
        }
    }
    if argv.is_empty() {
        return Err("No script given".into());
    }
    Ok((argv, options))
}

/// Bytes with an optional unit: 512, 64k, 10m, 1g.
fn parse_size(text: &str) -> Result<usize, String> {
    let lower = text.to_ascii_lowercase();
    let unit = |suffix: char| lower.strip_suffix(suffix);
    let (number, shift) = if let Some(number) = unit('k') {
        (number, 10)
    } else if let Some(number) = unit('m') {
        (number, 20)
    } else if let Some(number) = unit('g') {
        (number, 30)
    } else {
        (lower.as_str(), 0)
    };
    number.parse::<usize>().ok().and_then(|count| count.checked_mul(1 << shift)).ok_or(format!("Not a size: {text}"))
}

/// `argv[0]` is the script, the rest are its arguments.
fn run(argv: &[String], options: Options) -> u8 {
    // The first worker runs the script alone. If it registers no server, it was an ordinary
    // script and runs only once.
    let (first, ready) = spawn_worker(argv, options);
    let listeners = match ready {
        Ok(listeners) if !listeners.is_empty() => listeners,
        // A script without servers: its status, or that of exit() in timers and background work
        Ok(_) => return first.thread.join().unwrap_or(1),
        Err(status) => {
            let _ = first.thread.join();
            return status;
        }
    };
    let mut workers = vec![first];
    for _ in 1..options.workers {
        let (worker, ready) = spawn_worker(argv, options);
        workers.push(worker);
        if let Err(status) = ready {
            // Collect all workers first: shutting PHP down while one is still running would crash
            stop_workers(workers);
            return status;
        }
    }

    let ports: BTreeSet<u16> = listeners.iter().map(|listener| listener.port).collect();
    let senders: Vec<_> = workers.iter().map(|worker| worker.connections.clone()).collect();
    let runtime = tokio::runtime::Builder::new_current_thread().enable_all().build().expect("tokio does not start");
    runtime.block_on(async move {
        for port in ports {
            tokio::spawn(accept(port, senders.clone()));
        }
        tokio::select! {
            () = shutdown_signal() => {}
            // exit() outside a request also ends a server
            () = php::EXIT.notified() => eprintln!("tenma: exit() outside a request, shutting down"),
        }
    });
    drop(runtime);
    stop_workers(workers);
    php::exit_status().unwrap_or(0)
}

/// Closed channels end the workers. Close all first, then wait: this way they shut down at the
/// same time, not one after another with up to DRAIN of waiting each.
fn stop_workers(workers: Vec<Worker>) {
    let threads: Vec<_> = workers.into_iter().map(|worker| worker.thread).collect();
    for thread in threads {
        let _ = thread.join();
    }
}

/// Starts a worker thread and waits until its script has run.
fn spawn_worker(argv: &[String], options: Options) -> (Worker, Ready) {
    let argv = argv.to_vec();
    let (ready_sender, ready) = mpsc::channel();
    let (connections, receiver) = unbounded_channel();
    let thread = std::thread::Builder::new()
        .stack_size(WORKER_STACK)
        .spawn(move || worker(&argv, &ready_sender, receiver, options))
        .expect("worker thread does not start");
    let ready = ready.recv().unwrap_or(Err(1));
    (Worker { connections, thread }, ready)
}

/// Ctrl+C or SIGTERM, as sent by Docker, systemd and Kubernetes to stop.
async fn shutdown_signal() {
    let mut terminate = tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate()).expect("cannot catch SIGTERM");
    tokio::select! {
        _ = tokio::signal::ctrl_c() => {}
        _ = terminate.recv() => {}
    }
    eprintln!("tenma: shutting down");
}

fn worker(argv: &[String], ready: &mpsc::Sender<Ready>, mut connections: UnboundedReceiver<Connection>, options: Options) -> u8 {
    let mut first = true;
    let mut status = 0;
    loop {
        if let Err(message) = php::thread_start() {
            eprintln!("tenma: {message}");
            if !first {
                // A worker missing after a restart would silently swallow every Nth connection
                eprintln!("tenma: worker does not restart, exiting");
                std::process::exit(1);
            }
            let _ = ready.send(Err(1));
            return 1;
        }
        BROKEN.set(false);
        HANDLED.set(0);
        let stop = match load(argv) {
            Ok(listeners) => {
                if first {
                    let _ = ready.send(Ok(listeners.clone()));
                }
                if listeners.is_empty() {
                    // If the script ended with exit() or die(), tenma starts nothing it had scheduled
                    if !php::script_exited() {
                        status = run_until_idle();
                    }
                    Stop::Closed
                } else {
                    serve(&listeners, &mut connections, options)
                }
            }
            Err(status) => {
                if first {
                    let _ = ready.send(Err(status));
                } else {
                    // The script failed on restart: without the worker, carrying on does not help
                    eprintln!("tenma: script fails on worker restart, exiting");
                    std::process::exit(1);
                }
                Stop::Closed
            }
        };
        php::thread_stop();
        first = false;
        if let Stop::Closed = stop {
            return status;
        }
    }
}

/// A script without servers: like Node, the event loop runs while timers or other ops are pending.
/// Ends with the status of exit() in timers or background work, 255 after a fatal error.
fn run_until_idle() -> u8 {
    let runtime = tokio::runtime::Builder::new_current_thread().enable_all().build().expect("tokio does not start");
    let restart = RESTART.with(Rc::clone);
    let status = ops::in_loop(|| {
        LocalSet::new().block_on(&runtime, async {
            if php::started().is_err() {
                return 255;
            }
            tokio::select! {
                // In order: if exit() also ends the last op, the status of exit() counts
                biased;
                () = php::EXIT.notified() => php::exit_status().unwrap_or(0),
                // A fatal error: without servers there is no restart, the script has failed
                () = restart.notified() => 255,
                () = ops::idle() => 0,
            }
        })
    });
    if BROKEN.get() { 255 } else { php::exit_status().unwrap_or(status) }
}

/// Loads the standard library and the script into this thread's PHP interpreter.
fn load(argv: &[String]) -> Result<Vec<php::Listener>, u8> {
    if let Err(message) = php::eval(STDLIB.trim_start_matches("<?php"), "tenma:stdlib") {
        eprintln!("tenma: {message}");
        return Err(1);
    }
    match php::execute(argv) {
        Ok(0) => Ok(php::listeners()),
        Ok(status) => Err(status as u8),
        Err(message) => {
            eprintln!("tenma: {message}");
            Err(1)
        }
    }
}

/// A worker's event loop: PHP may only run on this thread, hence current_thread and LocalSet.
/// Ends when no more connections come or the interpreter should restart; running requests may
/// still finish then, except after a fatal error.
fn serve(listeners: &[php::Listener], connections: &mut UnboundedReceiver<Connection>, options: Options) -> Stop {
    let handlers: HashMap<u16, (u32, std::sync::Arc<Vec<files::Mount>>)> = listeners.iter().map(|listener| (listener.port, (listener.handler, listener.mounts.clone()))).collect();
    let runtime = tokio::runtime::Builder::new_current_thread().enable_all().build().expect("tokio does not start");
    let local = LocalSet::new();
    // Each connection holds a receiver; drain sends the stop signal and waits until all are gone
    let (drain, drained) = tokio::sync::watch::channel(());
    let restart = RESTART.with(Rc::clone);
    // A restart signal from the previous interpreter (e.g. a fatal error and max-requests at once)
    // does not apply to this one
    let _ = futures_util::FutureExt::now_or_never(restart.notified());
    let stop = ops::in_loop(|| local.block_on(&runtime, async {
        if php::started().is_err() {
            // If the startup work already fails, every restart would fail again: tenma shuts down
            // cleanly, the other workers still finish their requests
            eprintln!("tenma: fatal error in the script's startup work, shutting down");
            php::request_exit(255);
            return Stop::Closed;
        }
        loop {
            tokio::select! {
                connection = connections.recv() => {
                    let Some((port, stream)) = connection else {
                        break Stop::Closed;
                    };
                    let (Ok(remote), Ok(local)) = (stream.peer_addr(), stream.local_addr()) else {
                        continue;
                    };
                    let (Some((handler, mounts)), Ok(stream)) = (handlers.get(&port), TcpStream::from_std(stream)) else {
                        continue;
                    };
                    let (handler, mounts) = (*handler, mounts.clone());
                    let peers = (remote, local);
                    let service = service_fn(move |request| respond(handler, mounts.clone(), request, options, peers));
                    let mut drained = drained.clone();
                    tokio::task::spawn_local(async move {
                        let connection = http1::Builder::new()
                            .timer(hyper_util::rt::TokioTimer::new())
                            .header_read_timeout(options.request_timeout)
                            .serve_connection(TokioIo::new(stream), service)
                            .with_upgrades();
                        tokio::pin!(connection);
                        tokio::select! {
                            _ = connection.as_mut() => {}
                            _ = drained.changed() => {
                                connection.as_mut().graceful_shutdown();
                                let _ = connection.await;
                            }
                        }
                    });
                }
                () = restart.notified() => break Stop::Restart,
            }
        }
    }));
    drop(drained);
    // Running responses may finish, even after a fatal error (e.g. the error page): every path
    // into PHP checks BROKEN, so broken PHP does not run again
    ops::in_loop(|| {
        local.block_on(&runtime, async {
            let _ = drain.send(());
            let _ = tokio::time::timeout(DRAIN, drain.closed()).await;
        })
    });
    stop
}

/// Accepts connections and hands them to the workers round-robin.
// ponytail: round-robin without looking at load; least busy workers first if requests differ a lot in cost
async fn accept(port: u16, workers: Vec<UnboundedSender<Connection>>) {
    let socket = match TcpListener::bind(("0.0.0.0", port)).await {
        Ok(socket) => socket,
        Err(error) => {
            eprintln!("tenma: port {port} not available: {error}");
            std::process::exit(1);
        }
    };
    for next in (0..workers.len()).cycle() {
        let Ok((stream, _)) = socket.accept().await else {
            continue;
        };
        if let Ok(stream) = stream.into_std() {
            let _ = workers[next].send((port, stream));
        }
    }
}

/// Body of a response: complete or piece by piece (from PHP or a file).
pub type ResponseBody = Either<Full<Bytes>, streams::ChannelBody>;

/// `peers`: remote and local address of the connection.
async fn respond(
    handler: u32,
    mounts: std::sync::Arc<Vec<files::Mount>>,
    request: Request<Incoming>,
    options: Options,
    peers: (std::net::SocketAddr, std::net::SocketAddr),
) -> Result<Response<ResponseBody>, Infallible> {
    // The file first, if there is one; otherwise the handler
    if !mounts.is_empty()
        && let Some(response) = files::serve(&mounts, request.method(), request.uri(), request.headers()).await
    {
        return Ok(response);
    }
    let (mut parts, body) = request.into_parts();
    // ponytail: body fully in memory up to the limit; streams when large uploads come
    let body = match tokio::time::timeout(options.request_timeout, Limited::new(body, options.max_body).collect()).await {
        Ok(Ok(collected)) => collected.to_bytes(),
        Ok(Err(error)) if error.downcast_ref::<http_body_util::LengthLimitError>().is_some() => {
            return Ok(plain(StatusCode::PAYLOAD_TOO_LARGE, "Payload Too Large\n"));
        }
        // Aborted upload, broken chunked encoding
        Ok(Err(_)) => return Ok(plain(StatusCode::BAD_REQUEST, "Bad Request\n")),
        Err(_) => return Ok(plain(StatusCode::REQUEST_TIMEOUT, "Request Timeout\n")),
    };
    if BROKEN.get() {
        return Ok(plain(StatusCode::SERVICE_UNAVAILABLE, "Service Unavailable\n"));
    }
    let upgrade = parts.extensions.remove::<hyper::upgrade::OnUpgrade>();
    let uri = parts.uri.to_string();
    let request = php::Request {
        method: parts.method.as_str().as_bytes(),
        uri: uri.as_bytes(),
        headers: parts.headers.iter().map(|(name, value)| (name.as_str().as_bytes(), value.as_bytes())).collect(),
        body: &body,
        remote: peers.0,
        local: peers.1,
    };
    // PHP runs on the loop thread until the handler responds or its fiber waits for an op
    let failed = || php::Reply::Full(php::Response { status: 500, headers: Vec::new(), body: b"Internal Server Error\n".to_vec() });
    let reply = match php::handle(handler, &request) {
        php::Outcome::Pending(pending) => pending.await.unwrap_or_else(|_| failed()),
        php::Outcome::Failed => failed(),
        php::Outcome::Fatal => {
            interpreter_broken();
            failed()
        }
        php::Outcome::Broken(receiver) => {
            interpreter_broken();
            receiver.await.unwrap_or_else(|_| failed())
        }
    };
    HANDLED.set(HANDLED.get() + 1);
    if options.max_requests.is_some_and(|max| HANDLED.get() == max) {
        RESTART.with(|restart| restart.notify_one());
    }
    let (status, headers, body) = match reply {
        php::Reply::Full(response) => (response.status, response.headers, Either::Left(Full::new(Bytes::from(response.body)))),
        php::Reply::Stream { status, headers, body } => (status, headers, Either::Right(streams::ChannelBody(body))),
        php::Reply::Upgrade(pipes) => return Ok(accept_websocket(&parts.headers, upgrade, pipes)),
    };
    let mut builder = Response::builder().status(StatusCode::from_u16(status).unwrap_or(StatusCode::INTERNAL_SERVER_ERROR));
    for (name, value) in headers {
        builder = builder.header(name, value);
    }
    Ok(builder.body(body).unwrap_or_else(|_| plain(StatusCode::INTERNAL_SERVER_ERROR, "Invalid response headers\n")))
}

/// Responds with 101 and hands the connection to PHP after the upgrade.
fn accept_websocket(headers: &hyper::HeaderMap, upgrade: Option<hyper::upgrade::OnUpgrade>, pipes: streams::Pipes) -> Response<ResponseBody> {
    let (Some(key), Some(upgrade)) = (headers.get("sec-websocket-key"), upgrade) else {
        return plain(StatusCode::BAD_REQUEST, "Not a WebSocket request\n");
    };
    let accept = tokio_tungstenite::tungstenite::handshake::derive_accept_key(key.as_bytes());
    tokio::task::spawn_local(streams::run_websocket(upgrade, pipes));
    Response::builder()
        .status(StatusCode::SWITCHING_PROTOCOLS)
        .header("connection", "upgrade")
        .header("upgrade", "websocket")
        .header("sec-websocket-accept", accept)
        .body(Either::Left(Full::new(Bytes::new())))
        .unwrap_or_else(|_| plain(StatusCode::INTERNAL_SERVER_ERROR, "WebSocket failed\n"))
}

fn plain(status: StatusCode, text: &'static str) -> Response<ResponseBody> {
    let mut response = Response::new(Either::Left(Full::new(Bytes::from_static(text.as_bytes()))));
    *response.status_mut() = status;
    response
}
