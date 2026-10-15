//! Tenma: PHP with Node's execution model. Each worker is a thread with its own PHP interpreter
//! and its own event loop; the script runs once per worker, then the registered servers handle
//! requests until Ctrl+C. The main thread accepts connections and hands them to the workers
//! round-robin.
//!
//! A fatal PHP error only breaks the interpreter of one worker: the worker restarts it and runs
//! the script again. Ctrl+C or SIGTERM stop accepting connections and let running requests
//! finish. With --watch, all workers restart that way when a file the script loaded changes.

mod db;
mod dev;
mod files;
mod fs;
mod inspect;
mod ops;
mod php;
mod pubsub;
mod streams;
mod watch;

use std::cell::{Cell, RefCell};
use std::collections::{BTreeSet, HashMap};
use std::convert::Infallible;
use std::process::ExitCode;
use std::rc::Rc;
use std::sync::{Arc, mpsc};
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
const USAGE: &str = "Usage: tenma [--watch] [--inspect[-brk][=[HOST:]PORT]] [--workers N|auto] [--max-body SIZE] [--max-requests N] [--db-pool N] [-d name=value] [-c php.ini] [--server-name NAME] [--request-timeout SECONDS] [--max-connections N] [--pubsub redis://HOST] <script.php> [arguments …]\n       tenma build [tenma.dev.php]   (runs the hooks of tenma.dev.php once)\n       tenma --stubs > tenma.php   (the PHP API as stubs for IDEs and PHPStan)\n       tenma --version";
/// How long running requests may take to finish on shutdown or restart.
const DRAIN: Duration = Duration::from_secs(10);
/// With --watch: an open SSE or WebSocket connection should not hold up every reload for 10 s.
const WATCH_DRAIN: Duration = Duration::from_secs(1);
/// How long the script waits for the first run of the hooks: a hook that never ends (a command
/// with its own --watch) must not keep the app from starting.
const HOOKS_FIRST_RUN: Duration = Duration::from_secs(10);
/// PHP expects the large stack of a main thread, not the 2 MB of a Rust thread.
const WORKER_STACK: usize = 8 * 1024 * 1024;

/// An accepted connection and the port it came in on.
type Connection = (u16, std::net::TcpStream);

/// What a worker reports after running the script: its servers or the exit status.
type Ready = Result<Vec<php::Listener>, u8>;

struct Worker {
    connections: UnboundedSender<Connection>,
    /// --watch: a file changed, the worker runs the script again.
    change: Arc<Notify>,
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
    /// How long the headers and body of a request may take to arrive (against Slowloris), and how
    /// long a write to a client may stall.
    request_timeout: Duration,
    /// Open connections per worker; more get 503.
    max_connections: usize,
    /// Reload when a file the script loaded changes.
    watch: bool,
    /// How long running requests may take to finish on shutdown or restart.
    drain: Duration,
    /// --inspect: the address of the debugger (DAP).
    inspect: Option<std::net::SocketAddr>,
    /// --inspect-brk: wait for the debugger and stop at the script's first statement.
    inspect_brk: bool,
}

/// Why a worker's event loop ends.
enum Stop {
    /// No more connections: tenma shuts down.
    Closed,
    /// Restart the interpreter and run the script again.
    Restart,
    /// --watch: the script failed or ended, run it again after the next change.
    Wait,
}

thread_local! {
    /// Wakes the worker's event loop so it restarts its interpreter.
    static RESTART: Rc<Notify> = Rc::new(Notify::new());
    /// --watch: a file changed. Apart from RESTART, which a fatal error or --max-requests sends
    /// and which does not outlive the interpreter it was meant for.
    static CHANGE: RefCell<Arc<Notify>> = RefCell::new(Arc::new(Notify::new()));
    /// After a fatal error: this thread's PHP must not run again until the restart.
    static BROKEN: Cell<bool> = const { Cell::new(false) };
    /// Requests since the interpreter last started.
    static HANDLED: Cell<u64> = const { Cell::new(0) };
}

/// This worker's interpreter is unusable after a fatal error: restart it right away.
pub fn interpreter_broken() {
    eprintln!("tenma: fatal PHP error, restarting worker");
    BROKEN.set(true);
    // Their fibers are gone with the interpreter: pending requests get their 500 and streams end
    // now, not after the drain
    php::forget_waiting();
    streams::clear();
    RESTART.with(|restart| restart.notify_one());
}

pub fn broken() -> bool {
    BROKEN.get()
}

fn main() -> ExitCode {
    match std::env::args().nth(1).as_deref() {
        Some("--stubs") => return stubs(),
        Some("--version" | "-v") => {
            println!("tenma {} (PHP {})", env!("CARGO_PKG_VERSION"), env!("TENMA_PHP_VERSION"));
            return ExitCode::SUCCESS;
        }
        _ => {}
    }
    let (argv, options) = match parse_args() {
        Ok(parsed) => parsed,
        Err(message) => {
            eprintln!("{message}\n{USAGE}");
            return ExitCode::from(2);
        }
    };
    streams::MAX_MESSAGE.store(options.max_body, std::sync::atomic::Ordering::Relaxed);
    if options.watch {
        watch::configure();
    }
    if options.inspect.is_some() {
        inspect::configure();
    }
    if let Err(message) = php::start() {
        eprintln!("tenma: {message}");
        return ExitCode::FAILURE;
    }
    if let Some(address) = options.inspect
        && let Err(message) = inspect::listen(address, options.inspect_brk)
    {
        eprintln!("tenma: {message}");
        php::stop();
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
    let mut options = Options {
        workers: 1,
        max_body: 10 << 20,
        max_requests: None,
        request_timeout: Duration::from_secs(30),
        max_connections: 1024,
        watch: false,
        drain: DRAIN,
        inspect: None,
        inspect_brk: false,
    };
    let mut argv = Vec::new();
    let mut pool = None;
    while let Some(arg) = args.next() {
        let mut value = |name: &str| args.next().ok_or(format!("{name} needs a value"));
        match arg.as_str() {
            "--watch" => {
                options.watch = true;
                options.drain = WATCH_DRAIN;
            }
            "--inspect" | "--inspect-brk" => {
                options.inspect = Some(inspect::DEFAULT);
                options.inspect_brk = arg == "--inspect-brk";
            }
            // As with node: --inspect=9230 or --inspect=host:port
            inspect if inspect.starts_with("--inspect=") || inspect.starts_with("--inspect-brk=") => {
                options.inspect_brk = inspect.starts_with("--inspect-brk=");
                let address = &inspect[inspect.find('=').unwrap_or_default() + 1..];
                let parsed = match address.parse::<u16>() {
                    Ok(port) => Some(std::net::SocketAddr::new(inspect::DEFAULT.ip(), port)),
                    Err(_) => std::net::ToSocketAddrs::to_socket_addrs(address).ok().and_then(|mut addresses| addresses.next()),
                };
                options.inspect = Some(parsed.ok_or(format!("--inspect: not an address: {address}"))?);
            }
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
                pool = Some(size);
            }
            // As with php: -d name=value or -dname=value, -c path
            "-d" => php::define_ini(&value("-d")?)?,
            define if define.starts_with("-d") => php::define_ini(&define[2..])?,
            "-c" => php::set_ini_path(&value("-c")?)?,
            "--request-timeout" => {
                let seconds = value("--request-timeout")?;
                options.request_timeout = seconds.parse::<f64>().ok().filter(|&s| s > 0.0).map(Duration::from_secs_f64).ok_or(format!("--request-timeout {seconds}: not a number above 0"))?;
            }
            "--max-connections" => {
                let number = value("--max-connections")?;
                options.max_connections = number.parse().ok().filter(|&count| count > 0).ok_or(format!("--max-connections {number}: not a number above 0"))?;
            }
            "--server-name" => php::set_server_name(&value("--server-name")?)?,
            "--pubsub" => pubsub::use_redis(&value("--pubsub")?)?,
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
    // Requests would land in different interpreters; while debugging that only confuses
    if options.inspect.is_some() && options.workers > 1 {
        eprintln!("tenma: --inspect runs one worker");
        options.workers = 1;
    }
    // About 20 connections in all by default, Postgres allows 100: ten workers with ten each ran out of them
    db::POOL_SIZE.store(pool.unwrap_or((20 / options.workers).clamp(2, 10)), std::sync::atomic::Ordering::Relaxed);
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
    if argv[0] == "build" {
        return build(argv.get(1).map_or(dev::FILE, String::as_str), options);
    }
    let mut changes = if options.watch {
        match watch::start() {
            Ok(changes) => Some(changes),
            Err(message) => {
                eprintln!("tenma: {message}");
                return 1;
            }
        }
    } else {
        None
    };
    // tenma.dev.php first: the first request should find what its hooks write
    let dev_worker = changes.as_ref().and(dev::find(&argv[0])).map(|path| {
        let settled = dev::settled_receiver();
        let (worker, ready) = spawn_worker(&[path.to_string_lossy().into_owned()], options, Some(BTreeSet::new()), true);
        if ready.is_ok() && settled.recv_timeout(HOOKS_FIRST_RUN) == Err(mpsc::RecvTimeoutError::Timeout) {
            eprintln!("tenma: hooks still running after {} s, starting the script anyway", HOOKS_FIRST_RUN.as_secs());
        }
        worker
    });
    // The first worker runs the script alone. If it registers no server, it was an ordinary
    // script and runs only once; with --watch, the worker runs it again after every change.
    let (first, ready) = spawn_worker(argv, options, None, false);
    let listeners = match ready {
        Ok(listeners) => listeners,
        Err(status) => {
            stop_workers([first].into_iter().chain(dev_worker).collect());
            return status;
        }
    };
    // A script without servers: its status, or that of exit() in timers and background work
    if listeners.is_empty() && !options.watch {
        return first.thread.join().unwrap_or(1);
    }
    let ports: BTreeSet<u16> = listeners.iter().map(|listener| listener.port).collect();
    let serving = !ports.is_empty();
    let mut workers = vec![first];
    for _ in 1..if serving { options.workers } else { 1 } {
        let (worker, ready) = spawn_worker(argv, options, Some(ports.clone()), false);
        workers.push(worker);
        if let Err(status) = ready {
            // Collect all workers first: shutting PHP down while one is still running would crash
            workers.extend(dev_worker);
            stop_workers(workers);
            return status;
        }
    }

    let senders: Vec<_> = workers.iter().map(|worker| worker.connections.clone()).collect();
    let changed: Vec<_> = workers.iter().map(|worker| worker.change.clone()).collect();
    let dev_changed = dev_worker.as_ref().map(|worker| worker.change.clone());
    let runtime = tokio::runtime::Builder::new_current_thread().enable_all().build().expect("tokio does not start");
    runtime.block_on(async move {
        for port in ports {
            tokio::spawn(accept(port, senders.clone()));
        }
        let shutdown = shutdown_signal();
        tokio::pin!(shutdown);
        loop {
            tokio::select! {
                () = &mut shutdown => break,
                // exit() outside a request also ends a server; a script without servers waits for changes
                () = php::EXIT.notified(), if serving => {
                    eprintln!("tenma: exit() outside a request, shutting down");
                    break;
                }
                () = dev::EXIT.notified(), if dev_changed.is_some() => {
                    eprintln!("tenma: exit() in a hook, shutting down");
                    break;
                }
                Some(batch) = next_change(&mut changes) => {
                    if let (Some(path), Some(change)) = (batch.dev, &dev_changed) {
                        eprintln!("tenma: {} changed, reloading the hooks", shown(&path));
                        change.notify_one();
                    }
                    if let Some(path) = batch.app {
                        eprintln!("tenma: {} changed, reloading", shown(&path));
                        for change in &changed {
                            change.notify_one();
                        }
                    }
                }
            }
        }
    });
    drop(runtime);
    inspect::release();
    workers.extend(dev_worker);
    stop_workers(workers);
    dev::exit_status().or(php::exit_status()).unwrap_or(0)
}

/// `tenma build`: runs the hooks of tenma.dev.php once, in order; 1 if one fails.
fn build(path: &str, options: Options) -> u8 {
    let Ok(path) = std::path::Path::new(path).canonicalize() else {
        eprintln!("tenma: {path} not found");
        return 1;
    };
    let (worker, ready) = spawn_worker(&[path.to_string_lossy().into_owned()], options, None, true);
    let status = worker.thread.join().unwrap_or(1);
    ready.err().unwrap_or(status)
}

/// The next change --watch reports; never without --watch.
async fn next_change(changes: &mut Option<UnboundedReceiver<watch::Changes>>) -> Option<watch::Changes> {
    match changes {
        Some(changes) => changes.recv().await,
        None => std::future::pending().await,
    }
}

/// A path for messages: relative to the working directory if it lies below it.
fn shown(path: &std::path::Path) -> String {
    let relative = std::env::current_dir().ok().and_then(|cwd| path.strip_prefix(cwd.canonicalize().ok()?).ok().map(std::path::Path::to_path_buf));
    relative.as_deref().unwrap_or(path).display().to_string()
}

/// Closed channels end the workers. Close all first, then wait: this way they shut down at the
/// same time, not one after another with up to the drain time of waiting each.
fn stop_workers(workers: Vec<Worker>) {
    let threads: Vec<_> = workers.into_iter().map(|worker| worker.thread).collect();
    for thread in threads {
        let _ = thread.join();
    }
}

/// Starts a worker thread and waits until its script has run.
/// `ports`: those the main thread listens on, None for the first worker, which determines them.
/// `dev`: the worker runs tenma.dev.php, `argv[0]`.
fn spawn_worker(argv: &[String], options: Options, ports: Option<BTreeSet<u16>>, dev: bool) -> (Worker, Ready) {
    let argv = argv.to_vec();
    let (ready_sender, ready) = mpsc::channel();
    let (connections, receiver) = unbounded_channel();
    let change = Arc::new(Notify::new());
    let worker_change = change.clone();
    let thread = std::thread::Builder::new()
        .stack_size(WORKER_STACK)
        .spawn(move || worker(&argv, &ready_sender, receiver, worker_change, ports, dev, options))
        .expect("worker thread does not start");
    let ready = ready.recv().unwrap_or(Err(1));
    (Worker { connections, change, thread }, ready)
}

/// Ctrl+C or SIGTERM, as sent by Docker, systemd and Kubernetes to stop.
async fn shutdown_signal() {
    let mut terminate = tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate()).expect("cannot catch SIGTERM");
    tokio::select! {
        _ = tokio::signal::ctrl_c() => {}
        _ = terminate.recv() => {}
    }
    eprintln!("tenma: shutting down");
    // A second Ctrl+C or SIGTERM ends tenma at once, without waiting for the drain
    unsafe {
        libc::signal(libc::SIGINT, libc::SIG_DFL);
        libc::signal(libc::SIGTERM, libc::SIG_DFL);
    }
}

fn worker(argv: &[String], ready: &mpsc::Sender<Ready>, mut connections: UnboundedReceiver<Connection>, change: Arc<Notify>, mut ports: Option<BTreeSet<u16>>, dev: bool, options: Options) -> u8 {
    CHANGE.set(change);
    if dev {
        watch::dev_thread();
    }
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
        if options.inspect.is_some() && !dev {
            inspect::thread();
        }
        BROKEN.set(false);
        HANDLED.set(0);
        watch::forget();
        let loaded = load(argv, dev, options);
        // Also after a failure: the file with the syntax error is watched too
        watch::report();
        let stop = match loaded {
            Ok(listeners) => {
                if first {
                    let _ = ready.send(Ok(listeners.clone()));
                }
                let now: BTreeSet<u16> = listeners.iter().map(|listener| listener.port).collect();
                // No servers at all is no port change: an editor that truncates before writing, or a
                // script that no longer serves, waits for the next change instead of restarting tenma
                if options.watch && !dev && !now.is_empty() && *ports.get_or_insert_with(|| now.clone()) != now {
                    // ponytail: the whole process restarts; the listeners would have to move to new ports otherwise
                    eprintln!("tenma: the script's ports changed, restarting tenma");
                    restart_process();
                }
                if listeners.is_empty() {
                    // If the script ended with exit() or die(), tenma starts nothing it had scheduled
                    // With --watch, exit() in a hook ends tenma through dev::EXIT
                    let ended = if php::script_exited() { Ok(status) } else { run_until_idle(&mut connections, !(dev && options.watch), options) };
                    match ended {
                        // exit() in a hook: tenma is ending
                        Ok(_) if dev && dev::exit_status().is_some() => Stop::Closed,
                        Ok(ended) => {
                            status = ended;
                            if options.watch {
                                if dev {
                                    eprintln!("tenma: hooks stopped, waiting for a change to tenma.dev.php");
                                } else {
                                    eprintln!("tenma: script ended with status {status}, waiting for changes");
                                }
                                Stop::Wait
                            } else {
                                Stop::Closed
                            }
                        }
                        Err(stop) => stop,
                    }
                } else {
                    serve(&listeners, &mut connections, options)
                }
            }
            Err(failed) => {
                status = failed;
                if options.watch {
                    // Even on the first run: tenma waits for a fix, and restarts if it brings servers
                    if first {
                        let _ = ready.send(Ok(Vec::new()));
                    }
                    ports.get_or_insert_with(BTreeSet::new);
                    eprintln!("tenma: {} failed, waiting for changes", if dev { "tenma.dev.php" } else { "script" });
                    Stop::Wait
                } else if first {
                    let _ = ready.send(Err(status));
                    Stop::Closed
                } else {
                    // The script failed on restart: without the worker, carrying on does not help
                    eprintln!("tenma: script fails on worker restart, exiting");
                    std::process::exit(1);
                }
            }
        };
        php::thread_stop();
        first = false;
        match stop {
            Stop::Closed => return status,
            Stop::Restart => {}
            Stop::Wait => {
                if dev {
                    dev::settled();
                }
                // exit() of this run must not end the next one
                php::forget_exit();
                if let Stop::Closed = unavailable(&mut connections, options) {
                    return status;
                }
            }
        }
    }
}

/// Replaces the process with a fresh tenma with the same arguments.
fn restart_process() -> ! {
    use std::os::unix::process::CommandExt;
    let error = std::process::Command::new(std::env::current_exe().unwrap_or_else(|_| "tenma".into())).args(std::env::args_os().skip(1)).exec();
    eprintln!("tenma: cannot restart: {error}");
    std::process::exit(1);
}

/// --watch: the script failed or ended. Requests get 503 until the next change restarts the
/// worker; the error is on the console.
fn unavailable(connections: &mut UnboundedReceiver<Connection>, options: Options) -> Stop {
    let runtime = tokio::runtime::Builder::new_current_thread().enable_all().build().expect("tokio does not start");
    let change = CHANGE.with_borrow(Arc::clone);
    runtime.block_on(async {
        let mut answering = tokio::task::JoinSet::new();
        let stop = loop {
            tokio::select! {
                connection = connections.recv() => {
                    let Some((_, stream)) = connection else {
                        break Stop::Closed;
                    };
                    if let Some(refused) = refuse(stream, options, "The script failed to load, see the console. Waiting for changes.\n") {
                        answering.spawn(refused);
                    }
                    while answering.try_join_next().is_some() {}
                }
                () = change.notified() => break Stop::Restart,
            }
        };
        // Connections accepted just before the change still get their answer: dropping the runtime
        // would reset them while their request is already on the way
        let _ = tokio::time::timeout(options.drain, async { while answering.join_next().await.is_some() {} }).await;
        stop
    })
}

/// Answers a connection no script serves with 503, without keep-alive: it gets its answer and ends.
fn refuse(stream: std::net::TcpStream, options: Options, message: &'static str) -> Option<impl std::future::Future<Output = Result<(), hyper::Error>> + Send + 'static> {
    let stream = TcpStream::from_std(stream).ok()?;
    let service = service_fn(move |_| async move { Ok::<_, Infallible>(plain(StatusCode::SERVICE_UNAVAILABLE, message)) });
    Some(
        http1::Builder::new()
            .timer(hyper_util::rt::TokioTimer::new())
            .header_read_timeout(options.request_timeout)
            .keep_alive(false)
            .serve_connection(TokioIo::new(stream), service),
    )
}

/// A script without servers: like Node, the event loop runs while timers or other ops are pending.
/// Ends with the status of exit() in timers or background work, 255 after a fatal error. With
/// --watch, a change (Restart) or the end of tenma (Closed) stops it early.
fn run_until_idle(connections: &mut UnboundedReceiver<Connection>, exits: bool, options: Options) -> Result<u8, Stop> {
    let runtime = tokio::runtime::Builder::new_current_thread().enable_all().build().expect("tokio does not start");
    let restart = RESTART.with(Rc::clone);
    let change = CHANGE.with_borrow(Arc::clone);
    let ended = ops::in_loop(|| {
        LocalSet::new().block_on(&runtime, async {
            if php::started().is_err() {
                return Ok(255);
            }
            loop {
                tokio::select! {
                    // In order: if exit() also ends the last op, the status of exit() counts
                    biased;
                    () = php::EXIT.notified(), if exits => break Ok(php::exit_status().unwrap_or(0)),
                    // A fatal error: without servers there is no restart, the script has failed
                    () = restart.notified() => break Ok(255),
                    () = ops::idle() => break Ok(0),
                    () = change.notified() => break Err(Stop::Restart),
                    connection = connections.recv() => match connection {
                        None => break Err(Stop::Closed),
                        // With --watch the ports stay open while an edited script serves nothing
                        Some((_, stream)) => {
                            if let Some(refused) = refuse(stream, options, "The script serves no requests right now, see the console.\n") {
                                tokio::task::spawn_local(refused);
                            }
                        }
                    },
                }
            }
        })
    });
    let status = ended?;
    Ok(if BROKEN.get() { 255 } else { php::exit_status().unwrap_or(status) })
}

/// Loads the standard library and the script (or tenma.dev.php) into this thread's PHP interpreter.
fn load(argv: &[String], dev: bool, options: Options) -> Result<Vec<php::Listener>, u8> {
    if let Err(message) = php::eval(STDLIB.trim_start_matches("<?php"), "tenma:stdlib") {
        eprintln!("tenma: {message}");
        return Err(1);
    }
    if dev {
        return match php::eval(&dev::code(std::path::Path::new(&argv[0]), options.watch), "tenma:dev") {
            Ok(()) => Ok(Vec::new()),
            Err(message) => {
                eprintln!("tenma: {message}");
                Err(1)
            }
        };
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
    let open = Rc::new(Cell::new(0));
    let restart = RESTART.with(Rc::clone);
    // A restart signal from the previous interpreter (e.g. a fatal error and max-requests at once)
    // does not apply to this one; a change does, it may have come while the script was loading
    let _ = futures_util::FutureExt::now_or_never(restart.notified());
    let change = CHANGE.with_borrow(Arc::clone);
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
                // A restart first: the response that asked for it may already be out, and the client's
                // next connection waiting too. That one belongs to the fresh interpreter: here it would
                // land in the old one, broken after a fatal error, and the drain would reset it unread
                biased;
                () = restart.notified() => break Stop::Restart,
                () = change.notified() => break Stop::Restart,
                connection = connections.recv() => {
                    let Some((port, stream)) = connection else {
                        break Stop::Closed;
                    };
                    // Every connection holds memory and a file descriptor: beyond the limit a short answer
                    if open.get() >= options.max_connections {
                        if let Some(refused) = refuse(stream, options, "Too many connections, try again later.\n") {
                            tokio::task::spawn_local(refused);
                        }
                        continue;
                    }
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
                    let open = Open::count(&open);
                    tokio::task::spawn_local(async move {
                        let _open = open;
                        let connection = http1::Builder::new()
                            .timer(hyper_util::rt::TokioTimer::new())
                            .header_read_timeout(options.request_timeout)
                            .serve_connection(TokioIo::new(WriteTimeout::new(stream, options.request_timeout)), service)
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
            }
        }
    }));
    drop(drained);
    // Running responses may finish, even after a fatal error (e.g. the error page): every path
    // into PHP checks BROKEN, so broken PHP does not run again
    // ponytail: new connections wait meanwhile; an open event stream holds a --max-requests
    // restart up to the full drain. Upgrade: end event streams and WebSockets here, clients reconnect
    ops::in_loop(|| {
        local.block_on(&runtime, async {
            let _ = drain.send(());
            let _ = tokio::time::timeout(options.drain, drain.closed()).await;
        })
    });
    // Dropping the runtime would wait for every blocking task: a file op that hangs (a FIFO without
    // writer, a dead network mount) would keep the worker from restarting or tenma from ending
    drop(local);
    runtime.shutdown_timeout(Duration::from_millis(100));
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
    let mut next = 0;
    loop {
        let stream = match socket.accept().await {
            Ok((stream, _)) => stream,
            // Out of file descriptors (EMFILE): accept fails right away again; give connections time to close
            Err(_) => {
                tokio::time::sleep(Duration::from_millis(50)).await;
                continue;
            }
        };
        if let Ok(stream) = stream.into_std() {
            let _ = workers[next].send((port, stream));
            next = (next + 1) % workers.len();
        }
    }
}

/// Counts a worker's open connections while it lives.
struct Open(Rc<Cell<usize>>);

impl Open {
    fn count(open: &Rc<Cell<usize>>) -> Self {
        open.set(open.get() + 1);
        Self(Rc::clone(open))
    }
}

impl Drop for Open {
    fn drop(&mut self) {
        self.0.set(self.0.get() - 1);
    }
}

/// A connection whose writes may stall at most $limit: a client that stops reading is disconnected
/// instead of holding its task, its buffers and a stream writer that waits for room forever.
struct WriteTimeout {
    stream: TcpStream,
    limit: Duration,
    stalled: Option<std::pin::Pin<Box<tokio::time::Sleep>>>,
}

impl WriteTimeout {
    fn new(stream: TcpStream, limit: Duration) -> Self {
        Self { stream, limit, stalled: None }
    }

    /// Pending starts the clock, or fails once it ran out; any progress stops it.
    fn check<T>(&mut self, context: &mut std::task::Context<'_>, poll: std::task::Poll<std::io::Result<T>>) -> std::task::Poll<std::io::Result<T>> {
        if poll.is_ready() {
            self.stalled = None;
            return poll;
        }
        let limit = self.limit;
        let stalled = self.stalled.get_or_insert_with(|| Box::pin(tokio::time::sleep(limit)));
        match stalled.as_mut().poll(context) {
            std::task::Poll::Ready(()) => std::task::Poll::Ready(Err(std::io::ErrorKind::TimedOut.into())),
            std::task::Poll::Pending => std::task::Poll::Pending,
        }
    }
}

impl tokio::io::AsyncRead for WriteTimeout {
    fn poll_read(mut self: std::pin::Pin<&mut Self>, context: &mut std::task::Context<'_>, buffer: &mut tokio::io::ReadBuf<'_>) -> std::task::Poll<std::io::Result<()>> {
        std::pin::Pin::new(&mut self.stream).poll_read(context, buffer)
    }
}

impl tokio::io::AsyncWrite for WriteTimeout {
    fn poll_write(mut self: std::pin::Pin<&mut Self>, context: &mut std::task::Context<'_>, data: &[u8]) -> std::task::Poll<std::io::Result<usize>> {
        let poll = std::pin::Pin::new(&mut self.stream).poll_write(context, data);
        self.check(context, poll)
    }

    fn poll_write_vectored(mut self: std::pin::Pin<&mut Self>, context: &mut std::task::Context<'_>, data: &[std::io::IoSlice<'_>]) -> std::task::Poll<std::io::Result<usize>> {
        let poll = std::pin::Pin::new(&mut self.stream).poll_write_vectored(context, data);
        self.check(context, poll)
    }

    fn is_write_vectored(&self) -> bool {
        self.stream.is_write_vectored()
    }

    fn poll_flush(mut self: std::pin::Pin<&mut Self>, context: &mut std::task::Context<'_>) -> std::task::Poll<std::io::Result<()>> {
        let poll = std::pin::Pin::new(&mut self.stream).poll_flush(context);
        self.check(context, poll)
    }

    fn poll_shutdown(mut self: std::pin::Pin<&mut Self>, context: &mut std::task::Context<'_>) -> std::task::Poll<std::io::Result<()>> {
        std::pin::Pin::new(&mut self.stream).poll_shutdown(context)
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
        return Ok(close_if_restarting(response, options));
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
        return Ok(close_if_restarting(plain(StatusCode::SERVICE_UNAVAILABLE, "Service Unavailable\n"), options));
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
    // ponytail: checked after requests only; a file only a timer loads is watched from the next request on
    watch::report();
    let (status, headers, body) = match reply {
        php::Reply::Full(response) => (response.status, response.headers, Either::Left(Full::new(Bytes::from(response.body)))),
        php::Reply::Stream { status, headers, body } => (status, headers, Either::Right(body)),
        php::Reply::Upgrade(pipes) => return Ok(accept_websocket(&parts.headers, upgrade, pipes)),
    };
    let mut builder = Response::builder().status(StatusCode::from_u16(status).unwrap_or(StatusCode::INTERNAL_SERVER_ERROR));
    for (name, value) in headers {
        builder = builder.header(name, value);
    }
    Ok(close_if_restarting(builder.body(body).unwrap_or_else(|_| plain(StatusCode::INTERNAL_SERVER_ERROR, "Invalid response headers\n")), options))
}

/// After a fatal error or the last of --max-requests this interpreter takes no more requests: a
/// client that kept the connection would send its next one into the drain (reset, or a 503 from
/// the broken interpreter). Connection: close sends it to a new connection, served by the fresh one.
fn close_if_restarting(mut response: Response<ResponseBody>, options: Options) -> Response<ResponseBody> {
    if BROKEN.get() || options.max_requests.is_some_and(|max| HANDLED.get() >= max) {
        response.headers_mut().insert(hyper::header::CONNECTION, hyper::header::HeaderValue::from_static("close"));
    }
    response
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
