//! Starts the binary with a script and sends real HTTP requests.

use std::io::{Read, Write};
use std::net::TcpStream;
use std::process::{Child, Command};
use std::time::{Duration, Instant};

struct Server(Child);

impl Drop for Server {
    fn drop(&mut self) {
        let _ = self.0.kill();
    }
}

fn get(port: u16, path: &str) -> String {
    request(port, &format!("GET {path} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n"))
}

fn request(port: u16, raw: &str) -> String {
    let started = Instant::now();
    let mut stream = loop {
        match TcpStream::connect(("127.0.0.1", port)) {
            Ok(stream) => break stream,
            Err(_) if started.elapsed() < Duration::from_secs(5) => std::thread::sleep(Duration::from_millis(20)),
            Err(error) => panic!("server does not respond: {error}"),
        }
    };
    stream.write_all(raw.as_bytes()).unwrap();
    let mut response = String::new();
    stream.read_to_string(&mut response).unwrap();
    response
}

/// A request that leaves the connection open, as browsers do; read until the server closes it or 3 s pass.
fn keep_alive(port: u16, path: &str) -> String {
    let mut stream = TcpStream::connect(("127.0.0.1", port)).unwrap();
    stream.set_read_timeout(Some(Duration::from_secs(3))).unwrap();
    stream.write_all(format!("GET {path} HTTP/1.1\r\nHost: localhost\r\n\r\n").as_bytes()).unwrap();
    let mut response = Vec::new();
    let _ = stream.read_to_end(&mut response);
    String::from_utf8_lossy(&response).into_owned()
}

#[test]
fn serves_requests_and_survives_exceptions() {
    let port = 18_080;
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/hello.php").env("PORT", port.to_string()).spawn().unwrap());

    assert!(get(port, "/world").ends_with("Hello GET /world\n"));
    assert!(get(port, "/boom").starts_with("HTTP/1.1 500"));
    assert!(get(port, "/").starts_with("HTTP/1.1 200"));

    // A handler without a response only ends its request, the worker keeps running
    let before = get(port, "/count");
    assert!(get(port, "/null").starts_with("HTTP/1.1 500"));
    let after = get(port, "/count");
    let number = |response: &str| response.trim_end().rsplit(' ').next().unwrap().parse::<u32>().unwrap();
    assert_eq!(number(&after), number(&before) + 2, "no restart: {before} / {after}");
}

#[test]
fn async_calls_outside_a_handler() {
    let output = Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/top_level_op.php").output().unwrap();
    assert!(output.status.success(), "{output:?}");
    assert_eq!(String::from_utf8_lossy(&output.stdout), "rejected\n");
}

#[test]
fn classic_code_with_superglobals_header_and_echo() {
    let port = 18_081;
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/classic.php").env("PORT", port.to_string()).spawn().unwrap());

    let first = request(port, "GET /?name=world HTTP/1.1\r\nHost: localhost\r\nCookie: session=abc\r\nConnection: close\r\n\r\n");
    assert!(first.starts_with("HTTP/1.1 200"), "{first}");
    assert!(first.contains("x-count: 1"));
    assert!(first.contains("set-cookie: a=1") && first.contains("set-cookie: b=2"));
    assert!(first.ends_with("GET world abc"));

    // Headers and status of the first request do not stick to the second, the state in the process does
    let second = get(port, "/?name=x&missing=1");
    assert!(second.starts_with("HTTP/1.1 404"), "{second}");
    assert!(second.contains("x-count: 2"));
    assert_eq!(second.matches("set-cookie").count(), 2);
}

#[test]
fn fibers_wait_without_blocking_the_server() {
    let port = 18_082;
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/async.php").env("PORT", port.to_string()).spawn().unwrap());
    get(port, "/");

    // Ten requests that each sleep half a second are done together after just over half a second
    let started = Instant::now();
    let sleepers: Vec<_> = (0..10).map(|_| std::thread::spawn(move || get(port, "/slow"))).collect();
    for sleeper in sleepers {
        assert!(sleeper.join().unwrap().ends_with("slept enough\n"));
    }
    assert!(started.elapsed() < Duration::from_millis(1500), "{:?}", started.elapsed());

    assert!(get(port, "/proxy").ends_with("proxy: hello\n"));

    // Tenma::all: three waits run at the same time, not one after another
    let started = Instant::now();
    assert!(get(port, "/all").ends_with("{\"a\":\"one\",\"b\":\"two\",\"c\":\"three\"}\n"));
    assert!(started.elapsed() < Duration::from_millis(600), "{:?}", started.elapsed());
    assert!(get(port, "/all-error").ends_with("caught: broken\n"));
    // The interval from the script's startup keeps running next to the requests
    let ticks = |response: String| response.trim_end().rsplit(' ').next().unwrap().parse::<u32>().unwrap();
    let before = ticks(get(port, "/ticks"));
    std::thread::sleep(Duration::from_millis(300));
    assert!(ticks(get(port, "/ticks")) >= before + 4);
    let started = Instant::now();
    assert!(get(port, "/exit-task").starts_with("HTTP/1.1 500"));
    assert!(get(port, "/self-await").ends_with("rejected\n"));
    assert!(get(port, "/all-fast").ends_with("failed fast\n"));
    assert!(started.elapsed() < Duration::from_secs(1), "{:?}", started.elapsed());
    assert!(get(port, "/cancel-race").ends_with("b cancelled\n"));
    assert!(get(port, "/cancel-all").ends_with("children cancelled\n"));
    assert!(get(port, "/nested").ends_with("[\"hello\\n\",\"inner\"]\n"));
    assert!(get(port, "/unreachable").starts_with("HTTP/1.1 500"));
    let started = Instant::now();
    assert!(get(port, "/fetch-timeout").ends_with("timeout\n"));
    assert!(started.elapsed() < Duration::from_secs(1));
    // Output buffers belong to their request, even while many of them wait inside ob_start()
    let buffered: Vec<_> = (0..20).map(|id| std::thread::spawn(move || (id, get(port, &format!("/buffered?id={id}"))))).collect();
    for request in buffered {
        let (id, response) = request.join().unwrap();
        assert!(response.ends_with(&format!("\r\n\r\n<{id}>INNER {id}</{id}>\n")), "{response}");
    }
    // A normal response ends what the request started; only detach() outlives it
    assert!(get(port, "/leave-behind").ends_with("started\n"));
    let left = get(port, "/left");
    assert!(left.ends_with("{\"interval\":0,\"message\":0,\"task\":0,\"detached\":1,\"retried\":0,\"late\":0}\n"), "{left}");
    assert!(get(port, "/buffer-left-open").ends_with("level 1\n"));
    assert!(get(port, "/level").ends_with("level 0\n"));
    assert!(get(port, "/").ends_with("hello\n"));
}

#[test]
fn several_workers_with_their_own_state() {
    let port = 18_083;
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).args(["--workers", "3", "tests/fixtures/classic.php"]).env("PORT", port.to_string()).spawn().unwrap());

    // Round robin: each of the three workers counts for itself, so 1, 2 and 3 twice
    let mut counts: Vec<String> = (0..6)
        .map(|_| {
            let response = get(port, "/?name=x");
            response.lines().find_map(|line| line.strip_prefix("x-count: ")).unwrap().to_owned()
        })
        .collect();
    counts.sort();
    assert_eq!(counts, ["1", "1", "1", "2", "2", "2"]);
}

fn post(port: u16, path: &str, body: &str) -> String {
    request(port, &format!("POST {path} HTTP/1.1\r\nHost: localhost\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}", body.len()))
}

#[test]
fn fatal_error_restarts_only_the_worker() {
    let port = 18_084;
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/fatal.php").env("PORT", port.to_string()).spawn().unwrap());

    assert!(get(port, "/").ends_with("request 1\n"));
    // The broken interpreter takes no further request on this connection
    let failed = keep_alive(port, "/oom");
    assert!(failed.starts_with("HTTP/1.1 500") && failed.contains("connection: close"), "{failed}");
    // New interpreter, script ran again: the counter starts over
    assert!(get(port, "/").ends_with("request 1\n"));
    // After an op the 500 comes right away, not only after the drain
    let started = std::time::Instant::now();
    assert!(get(port, "/slow-oom").starts_with("HTTP/1.1 500"));
    assert!(started.elapsed() < std::time::Duration::from_secs(2), "{:?}", started.elapsed());
    let after = get(port, "/");
    assert!(after.ends_with("request 1\n"), "{after}");
}

#[test]
fn limits_for_body_and_requests_per_worker() {
    let port = 18_085;
    let _server = Server(
        Command::new(env!("CARGO_BIN_EXE_tenma"))
            .args(["--max-body", "1k", "--max-requests", "2", "tests/fixtures/fatal.php"])
            .env("PORT", port.to_string())
            .spawn()
            .unwrap(),
    );

    // Two requests per interpreter, then the worker restarts
    let counts: Vec<bool> = (0..4).map(|_| get(port, "/").ends_with("request 1\n")).collect();
    assert_eq!(counts, [true, false, true, false]);

    assert!(post(port, "/upload", &"x".repeat(1024)).ends_with("1024\n"));
    assert!(post(port, "/upload", &"x".repeat(1025)).starts_with("HTTP/1.1 413"));
}

#[test]
fn sigterm_lets_running_requests_finish() {
    let port = 18_086;
    let server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/fatal.php").env("PORT", port.to_string()).spawn().unwrap());
    get(port, "/");

    let slow = std::thread::spawn(move || get(port, "/slow"));
    std::thread::sleep(Duration::from_millis(150));
    Command::new("kill").args(["-TERM", &server.0.id().to_string()]).status().unwrap();
    assert!(slow.join().unwrap().ends_with("slept enough\n"));
}

#[test]
fn classic_with_input_uploads_and_exit() {
    let port = 18_087;
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/classic_io.php").env("PORT", port.to_string()).spawn().unwrap());

    assert!(post(port, "/input", "raw body").ends_with("input: raw body"));

    let boundary = "tenmaboundary";
    let body = format!(
        "--{boundary}\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nImage\r\n\
         --{boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"hello.txt\"\r\nContent-Type: text/plain\r\n\r\nHello Mars\r\n\
         --{boundary}--\r\n"
    );
    let upload = request(
        port,
        &format!("POST /upload HTTP/1.1\r\nHost: localhost\r\nContent-Type: multipart/form-data; boundary={boundary}\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}", body.len()),
    );
    assert!(upload.ends_with("Image hello.txt 10 Hello Mars"), "{upload}");

    let exited = get(port, "/exit");
    assert!(exited.starts_with("HTTP/1.1 200") && exited.contains("x-before-exit: yes") && exited.ends_with("before exit"), "{exited}");
    assert!(get(port, "/flush-all").ends_with("\r\n\r\nabc"));
    assert!(get(port, "/async").ends_with("\r\n\r\nrejected async too"));
    assert!(get(port, "/locked-buffer").ends_with("\r\n\r\ninside outside"));
    let warned = get(port, "/warn");
    assert!(warned.ends_with("\r\n\r\nonly this"), "{warned}");
    assert!(get(port, "/").ends_with("ok"));
}

#[test]
fn a_fatal_error_while_parsing_a_form_leaves_nothing_for_the_next_request() {
    let port = 18_137;
    let _server = Server(
        Command::new(env!("CARGO_BIN_EXE_tenma"))
            .args(["-d", "memory_limit=16M", "-d", "max_input_vars=1000000", "tests/fixtures/classic_io.php"])
            .env("PORT", port.to_string())
            .spawn()
            .unwrap(),
    );

    // So many fields that PHP's multipart parser runs out of memory halfway through
    let boundary = "tenmaboundary";
    let body: String = (0..120_000).map(|i| format!("--{boundary}\r\nContent-Disposition: form-data; name=\"f[]\"\r\n\r\nleftover{i}\r\n")).collect::<String>() + &format!("--{boundary}--\r\n");
    let failed = request(
        port,
        &format!("POST /upload HTTP/1.1\r\nHost: localhost\r\nContent-Type: multipart/form-data; boundary={boundary}\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}", body.len()),
    );
    assert!(failed.starts_with("HTTP/1.1 500"), "{}", &failed[..failed.len().min(200)]);
    // The fresh interpreter's php://input is this request's body, not the old one's freed memory
    let input = post(port, "/input", "raw body");
    assert!(input.ends_with("\r\n\r\ninput: raw body"), "{input}");
}

#[test]
fn a_silent_connection_does_not_hold_a_restart() {
    let port = 18_135;
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).args(["--max-requests", "1", "tests/fixtures/hello.php"]).env("PORT", port.to_string()).spawn().unwrap());
    get(port, "/");
    // Like a browser's preconnect: open, but no request yet
    let silent = TcpStream::connect(("127.0.0.1", port)).unwrap();
    std::thread::sleep(Duration::from_millis(100));
    // This request restarts the interpreter; the next one waits for the fresh one, not for the silent socket
    get(port, "/");
    let started = Instant::now();
    assert!(get(port, "/world").ends_with("Hello GET /world\n"));
    assert!(started.elapsed() < Duration::from_secs(2), "{:?}", started.elapsed());
    drop(silent);
}

#[test]
fn a_throwing_destructor_at_recycle_ends_only_the_interpreter() {
    let port = 18_136;
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).args(["--max-requests", "1", "tests/fixtures/scripts/destructor_on_recycle.php"]).env("PORT", port.to_string()).spawn().unwrap());
    for _ in 0..3 {
        assert!(get(port, "/").ends_with("\r\n\r\nok\n"));
    }
}

#[test]
fn sessions_per_visitor_without_leaks() {
    let port = 18_088;
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/session.php").env("PORT", port.to_string()).spawn().unwrap());

    let first = get(port, "/");
    assert!(first.ends_with("visit 1"), "{first}");
    let cookie = first.lines().find_map(|line| line.strip_prefix("set-cookie: ")).expect("session cookie missing");
    let cookie = cookie.split(';').next().unwrap();

    let with_cookie = |path: &str| request(port, &format!("GET {path} HTTP/1.1\r\nHost: localhost\r\nCookie: {cookie}\r\nConnection: close\r\n\r\n"));
    assert!(with_cookie("/").ends_with("visit 2"));
    assert!(with_cookie("/").ends_with("visit 3"));

    // A new visitor gets neither the ID nor the data of the previous one
    assert!(get(port, "/peek").ends_with("empty"));
    let stranger = get(port, "/");
    assert!(stranger.ends_with("visit 1"), "{stranger}");
    assert!(!stranger.contains(cookie));
}

#[test]
fn built_in_extensions_work() {
    let output = Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/extensions.php").output().unwrap();
    let stdout = String::from_utf8_lossy(&output.stdout);
    assert!(output.status.success(), "{stdout}{}", String::from_utf8_lossy(&output.stderr));
}

#[test]
fn streaming_sse_und_websockets() {
    use tokio_tungstenite::tungstenite::{Message, connect};

    let port = 18_089;
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/streams.php").env("PORT", port.to_string()).spawn().unwrap());
    get(port, "/");

    // The first word arrives at once, not only when the handler is done after 0.6 s
    let started = Instant::now();
    let mut stream = TcpStream::connect(("127.0.0.1", port)).unwrap();
    write!(stream, "GET /stream HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n").unwrap();
    let mut first = [0; 512];
    let read = stream.read(&mut first).unwrap();
    assert!(String::from_utf8_lossy(&first[..read]).contains("one"));
    assert!(started.elapsed() < Duration::from_millis(150), "{:?}", started.elapsed());
    let mut rest = String::new();
    stream.read_to_string(&mut rest).unwrap();
    assert!(rest.contains("two") && rest.contains("three"));

    let events = get(port, "/events");
    assert!(events.contains("content-type: text/event-stream"));
    assert!(events.contains("id: 1\nevent: greeting\ndata: hello\n\n"));
    assert!(events.contains("data: two\ndata: lines\n\n"));

    // If the client leaves, the handler notices on its next write
    let mut forever = TcpStream::connect(("127.0.0.1", port)).unwrap();
    write!(forever, "GET /forever HTTP/1.1\r\nHost: localhost\r\n\r\n").unwrap();
    forever.read_exact(&mut [0; 4096]).unwrap();
    drop(forever);
    std::thread::sleep(Duration::from_millis(200));
    assert!(get(port, "/closed").ends_with("closed"));

    // exit() in the stream: the client gets what was already written, and the connection ends
    let exited = get(port, "/exit-stream");
    assert!(exited.contains("before exit"), "{exited}");

    let (mut socket, _) = connect(format!("ws://127.0.0.1:{port}/ws")).unwrap();
    for word in ["hello", "world"] {
        socket.send(Message::text(word)).unwrap();
        assert_eq!(socket.read().unwrap(), Message::text(format!("echo: {word}")));
    }
    socket.close(None).unwrap();
    assert!(get(port, "/").ends_with("ok"));

    // An interval set in the handler ends with the connection instead of sending into a closed socket
    let (mut ticker, _) = connect(format!("ws://127.0.0.1:{port}/ws-ticker")).unwrap();
    assert_eq!(ticker.read().unwrap(), Message::text("tick"));
    ticker.close(None).unwrap();
    std::thread::sleep(Duration::from_millis(100));
    // The bodies only: the Date header may change in between
    let ticks = || get(port, "/ticks").rsplit("\r\n\r\n").next().unwrap().to_owned();
    let after_close = ticks();
    std::thread::sleep(Duration::from_millis(100));
    assert_eq!(ticks(), after_close);

    // 300 messages to a handler that never reads: tenma disconnects after 256, long before the handler ends
    let started = Instant::now();
    let (mut flood, _) = connect(format!("ws://127.0.0.1:{port}/ws-ignore")).unwrap();
    for _ in 0..300 {
        if flood.send(Message::text("flood")).is_err() {
            break;
        }
    }
    loop {
        match flood.read() {
            Ok(Message::Close(frame)) => {
                assert!(frame.is_some_and(|frame| frame.reason.contains("Too many unread messages")));
                break;
            }
            Ok(_) => continue,
            Err(_) => break,
        }
    }
    assert!(started.elapsed() < Duration::from_secs(2), "{:?}", started.elapsed());
}

#[test]
fn postgres_and_redis_async() {
    let port = 18_090;
    let user = std::env::var("USER").unwrap();
    let _server = Server(
        Command::new(env!("CARGO_BIN_EXE_tenma"))
            .arg("tests/fixtures/db.php")
            .env("PORT", port.to_string())
            .env("DATABASE_URL", std::env::var("DATABASE_URL").unwrap_or(format!("postgres://{user}@localhost/postgres")))
            .env("REDIS_URL", std::env::var("REDIS_URL").unwrap_or("redis://127.0.0.1:6379".into()))
            .spawn()
            .unwrap(),
    );
    if !get(port, "/setup").starts_with("HTTP/1.1 200") {
        eprintln!("no Postgres reachable, test skipped");
        return;
    }

    let inserted = get(port, "/insert?name=first");
    assert!(inserted.contains(r#""name": "first""#) && inserted.contains(r#""amount": 42"#) && inserted.contains(r#""price": 9.99"#), "{inserted}");
    let numeric = get(port, "/numeric");
    assert!(numeric.contains(r#""big": "123456789012345678.90""#) && numeric.contains(r#""small": 9.99"#), "{numeric}");
    assert!(inserted.contains(r#""tags": ["#) && inserted.contains(r#""created": ""#), "{inserted}");
    assert!(get(port, "/list").contains(r#""name": "first""#));
    assert!(get(port, "/rollback").contains(r#""n": 0"#));
    assert!(get(port, "/commit").ends_with("1\n"));
    assert!(get(port, "/script").contains(r#""n": 3"#));
    let failed = get(port, "/script-fails");
    assert!(failed.contains(r#""error": true"#) && failed.contains(r#""n": 0"#), "{failed}");
    assert!(get(port, "/error").contains("does_not_exist"));
    let started = Instant::now();
    let all_tx = get(port, "/all-tx");
    assert!(all_tx.contains("caught: on purpose"), "{all_tx}");
    assert!(started.elapsed() < Duration::from_secs(1), "{:?}", started.elapsed());
    let ordered = get(port, "/comment");
    assert!(ordered.contains("[\n    5,\n    4,\n    3,\n    2,\n    1\n]"), "{ordered}");

    // Ten queries with half a second of waiting each run at the same time, the worker does not block
    let started = Instant::now();
    let sleepers: Vec<_> = (0..10).map(|_| std::thread::spawn(move || get(port, "/slow"))).collect();
    for sleeper in sleepers {
        assert!(sleeper.join().unwrap().contains("slept"));
    }
    assert!(started.elapsed() < Duration::from_millis(1500), "{:?}", started.elapsed());

    let redis = get(port, "/redis");
    assert!(redis.contains(r#""value""#) && redis.contains("1,\n"), "{redis}");
    assert!(get(port, "/drop").starts_with("HTTP/1.1 200"));
}

#[test]
fn standard_streams_like_in_the_cli() {
    use std::process::Stdio;

    let mut child = Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/cli.php").stdin(Stdio::piped()).stdout(Stdio::piped()).stderr(Stdio::piped()).spawn().unwrap();
    child.stdin.take().unwrap().write_all(b"hello\n").unwrap();
    let output = child.wait_with_output().unwrap();
    assert!(output.status.success());
    assert_eq!(String::from_utf8_lossy(&output.stdout), "to stdout\ninput: hello\n");
    assert_eq!(String::from_utf8_lossy(&output.stderr), "to stderr\n");
}

#[test]
fn arguments_like_in_the_cli() {
    let output = Command::new(env!("CARGO_BIN_EXE_tenma")).args(["tests/fixtures/args.php", "import", "--force", "-w"]).output().unwrap();
    let argv = r#"["tests\/fixtures\/args.php","import","--force","-w"]"#;
    assert_eq!(String::from_utf8_lossy(&output.stdout).trim(), format!("[{argv},4,{argv},4,\"import\"]"));
}

#[test]
fn settings_with_minus_d_and_php_ini() {
    let output = Command::new(env!("CARGO_BIN_EXE_tenma"))
        .args(["-c", "tests/fixtures/php.ini", "-d", "memory_limit=256M", "-ddate.timezone=Europe/Berlin", "tests/fixtures/ini.php"])
        .output()
        .unwrap();
    // -d wins over the php.ini, values only from the php.ini stay, and so do tenma's own defaults
    assert_eq!(String::from_utf8_lossy(&output.stdout).trim(), r#"["256M","Europe\/Berlin","7","0"]"#);
}

#[test]
fn server_variables_and_addresses() {
    let port = 18_091;
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/server_vars.php").env("PORT", port.to_string()).spawn().unwrap());

    let classic = request(port, &format!("GET / HTTP/1.1\r\nHost: example.test:{port}\r\nConnection: close\r\n\r\n"));
    let root = std::fs::canonicalize("tests/fixtures").unwrap();
    let expected = format!(
        r#"{{"REMOTE_ADDR":"127.0.0.1","SERVER_ADDR":"127.0.0.1","SERVER_PORT":"{port}","SERVER_NAME":"localhost","REQUEST_SCHEME":"http","SCRIPT_NAME":"\/server_vars.php","PHP_SELF":"\/server_vars.php","DOCUMENT_ROOT":"{}","remote_port":true}}"#,
        root.display().to_string().replace('/', "\\/")
    );
    assert!(classic.ends_with(&expected), "{classic}");
    assert!(get(port + 1, "/").ends_with(&format!("127.0.0.1 {} localhost", port + 1)));
}

#[test]
fn shutdown_functions_per_request() {
    use std::process::Stdio;

    let port = 18_093;
    let server = Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/shutdown.php").env("PORT", port.to_string()).stdout(Stdio::piped()).spawn().unwrap();
    // If a check fails, no server may be left behind on the port
    struct KillOnDrop(u32);
    impl Drop for KillOnDrop {
        fn drop(&mut self) {
            let _ = Command::new("kill").args(["-KILL", &self.0.to_string()]).status();
        }
    }
    let guard = KillOnDrop(server.id());

    // Exactly once per request, also after exit() in the request and exit() in the shutdown function
    for path in ["/", "/exit", "/exit-shutdown"] {
        let response = get(port, path);
        assert!(response.starts_with("HTTP/1.1 200") && response.ends_with("request after"), "{path}: {response}");
    }
    assert!(get(port, "/count").ends_with("\r\n\r\n4"), "no restart up to here");

    // Exception in the shutdown function: fatal error like in PHP, response with 500, session closed, new worker
    let thrown = get(port, "/throw-shutdown");
    assert!(thrown.starts_with("HTTP/1.1 500") && thrown.ends_with("request after"), "{thrown}");
    let peek = get(port, "/peek");
    assert!(peek.ends_with("empty"), "{peek}");
    assert!(get(port, "/count").ends_with("\r\n\r\n2"), "new interpreter");

    // Fatal error in the request: the shutdown function still delivers the error page
    let fatal = get(port, "/fatal");
    // What was already output stays, like under FPM without output_buffering
    assert!(fatal.starts_with("HTTP/1.1 500") && fatal.ends_with("\r\n\r\nrequest after error page: fatal"), "{fatal}");
    assert!(get(port, "/count").ends_with("\r\n\r\n1"));

    let big = get(port, "/big-fatal");
    assert!(big.starts_with("HTTP/1.1 500") && big.ends_with(&"x".repeat(3_000_000)), "{} bytes", big.len());
    assert!(get(port, "/count").ends_with("\r\n\r\n1"));

    let compile = get(port, "/compile-error");
    assert!(compile.starts_with("HTTP/1.1 500") && compile.contains(" after trace "), "{compile}");
    assert!(get(port, "/count").ends_with("\r\n\r\n1"));

    // The function registered at startup never ran in a response, but at the end of every interpreter
    Command::new("kill").args(["-TERM", &server.id().to_string()]).status().unwrap();
    let output = server.wait_with_output().unwrap();
    // Exited and reaped: the PID may now belong to another process
    std::mem::forget(guard);
    assert_eq!(String::from_utf8_lossy(&output.stdout).matches("REGISTERED-AT-STARTUP").count(), 5);
}

#[test]
fn classic_requests_leave_no_state() {
    let port = 18_094;
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/isolation.php").env("PORT", port.to_string()).spawn().unwrap());

    assert!(post(port, "/set", "secret").ends_with("set"));
    // ini_set and set_time_limit back to the state after startup, php://input handle closed
    let after = post(port, "/", "from someone else");
    assert!(after.ends_with("10 0 closed"), "{after}");
}

#[test]
fn postgres_connections_come_back_clean() {
    let port = 18_095;
    let user = std::env::var("USER").unwrap();
    // A pool with exactly one connection: the second request is sure to get the same one
    let _server = Server(
        Command::new(env!("CARGO_BIN_EXE_tenma"))
            .args(["--db-pool", "1", "tests/fixtures/db.php"])
            .env("PORT", port.to_string())
            .env("DATABASE_URL", std::env::var("DATABASE_URL").unwrap_or(format!("postgres://{user}@localhost/postgres")))
            .env("REDIS_URL", std::env::var("REDIS_URL").unwrap_or("redis://127.0.0.1:6379".into()))
            .spawn()
            .unwrap(),
    );
    if !get(port, "/").starts_with("HTTP/1.1 200") || !get(port, "/whoami").starts_with("HTTP/1.1 200") {
        eprintln!("no Postgres reachable, test skipped");
        return;
    }
    // Redis connection gone: tenma rebuilds it on the next command instead of failing forever
    get(port, "/redis-kill");
    let pings: Vec<bool> = (0..3).map(|_| get(port, "/redis-ping").contains("PONG")).collect();
    assert!(pings.ends_with(&[true]), "{pings:?}");
    let wrongtype = get(port, "/redis-wrongtype");
    assert!(wrongtype.contains(r#""failed": true"#) && wrongtype.contains(r#""same": true"#), "{wrongtype}");

    assert!(get(port, "/taint").starts_with("HTTP/1.1 200"));
    // With only one connection in the pool everything would hang if exit() did not release it
    assert!(get(port, "/exit-tx").starts_with("HTTP/1.1 500"));
    assert!(get(port, "/cancel-tx").contains("cancelled"));
    let whoami = get(port, "/whoami");
    assert!(whoami.contains("\"name\": \"\""), "{whoami}");
    // Many requests at once share the one connection: the pool opens no second one while it is cleaned
    let backends: Vec<_> = (0..20).map(|_| std::thread::spawn(move || get(port, "/backend"))).collect();
    let pids: std::collections::HashSet<String> = backends.into_iter().map(|backend| backend.join().unwrap().rsplit("\r\n\r\n").next().unwrap().to_owned()).collect();
    assert_eq!(pids.len(), 1, "{pids:?}");
    // A type the connection met in an earlier request, then one it meets now: the cleanup between
    // them must keep what the driver prepared for looking types up
    assert!(get(port, "/types").starts_with("HTTP/1.1 200"));
    assert!(get(port, "/mood").contains(r#""value": "happy""#));
    let size = get(port, "/size");
    assert!(size.contains(r#""value": "big""#), "{size}");
    get(port, "/types-drop");
}

#[test]
fn classic_without_client_influence() {
    let port = 18_096;
    let _server = Server(
        Command::new(env!("CARGO_BIN_EXE_tenma")).args(["--server-name", "app.example", "tests/fixtures/security.php"]).env("PORT", port.to_string()).spawn().unwrap(),
    );
    let body = "0=post&only_post=1";
    let response = request(
        port,
        &format!(
            "POST /?0=get&only_get=1 HTTP/1.1\r\nHost: evil.example\r\nCookie: sid=victim; sid=attacker; a[b]=1; x.y=2\r\n\
             X-Forwarded-For: 10.0.0.1\r\nx_forwarded_for: 6.6.6.6\r\nContent-Type: application/x-www-form-urlencoded\r\n\
             Content-Length: {}\r\nConnection: close\r\n\r\n{body}",
            body.len()
        ),
    );
    // SERVER_NAME as configured, not from Host; the first cookie wins, names like under FPM;
    // the header with an underscore is dropped; POST overrides GET without renumbering
    let expected = r#"{"server_name":"app.example","host":"evil.example","cookies":{"sid":"victim","a":{"b":"1"},"x_y":"2"},"forwarded":"10.0.0.1","request":{"0":"post","only_get":"1","only_post":"1"}}"#;
    assert!(response.ends_with(expected), "{response}");
}

#[test]
fn slow_clients_are_disconnected() {
    let port = 18_097;
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).args(["--request-timeout", "0.5", "tests/fixtures/hello.php"]).env("PORT", port.to_string()).spawn().unwrap());
    get(port, "/");

    // Slowloris: the headers never arrive completely, tenma disconnects after the timeout
    let started = Instant::now();
    let mut slow = TcpStream::connect(("127.0.0.1", port)).unwrap();
    slow.set_read_timeout(Some(Duration::from_secs(3))).unwrap();
    write!(slow, "GET / HTTP/1.1\r\nHost: localhost\r\n").unwrap();
    let mut rest = Vec::new();
    let _ = slow.read_to_end(&mut rest);
    assert!(started.elapsed() < Duration::from_secs(2), "{:?}", started.elapsed());

    // The body arrives only halfway: 408
    let started = Instant::now();
    let mut stream = TcpStream::connect(("127.0.0.1", port)).unwrap();
    write!(stream, "POST / HTTP/1.1\r\nHost: localhost\r\nContent-Length: 100\r\n\r\nonly a part").unwrap();
    let mut response = String::new();
    stream.set_read_timeout(Some(Duration::from_secs(3))).unwrap();
    let _ = stream.read_to_string(&mut response);
    assert!(response.starts_with("HTTP/1.1 408"), "{response}");
    assert!(started.elapsed() < Duration::from_secs(2));
}

#[test]
fn restarts_without_leaks_or_double_signals() {
    use std::process::Stdio;

    struct KillOnDrop(u32);
    impl Drop for KillOnDrop {
        fn drop(&mut self) {
            let _ = Command::new("kill").args(["-KILL", &self.0.to_string()]).status();
        }
    }
    // Linux lists them in /proc, macOS has no /proc but lsof
    let open_files = |pid: u32| match std::fs::read_dir(format!("/proc/{pid}/fd")) {
        Ok(fds) => fds.count(),
        Err(_) => String::from_utf8_lossy(&Command::new("lsof").args(["-p", &pid.to_string()]).output().unwrap().stdout).lines().count(),
    };

    // One interpreter per request: 30 restarts must not cost file descriptors
    let port = 18_098;
    let restarting = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).args(["--max-requests", "1", "tests/fixtures/hello.php"]).env("PORT", port.to_string()).spawn().unwrap());
    // The first restarts open what every later one reuses (event loops, sockets), on a busy machine
    // spread over more of them: a leak grows with every restart, so compare two rounds of 30
    for _ in 0..30 {
        get(port, "/");
    }
    // The last request of an interpreter closes its connection: the next one goes to the fresh one
    let last = keep_alive(port, "/");
    assert!(last.contains("connection: close") && last.ends_with("Hello GET /\n"), "{last}");
    std::thread::sleep(Duration::from_millis(300));
    let before = open_files(restarting.0.id());
    for _ in 0..30 {
        get(port, "/");
    }
    // A restart still under way holds a descriptor or two for a moment; a leak stays
    let mut after = open_files(restarting.0.id());
    for _ in 0..10 {
        if after <= before + 2 {
            break;
        }
        std::thread::sleep(Duration::from_millis(100));
        after = open_files(restarting.0.id());
    }
    assert!(after <= before + 2, "{before} -> {after}");

    // Fatal error exactly on the max-requests request: one restart, not two
    let port = 18_099;
    let server = Command::new(env!("CARGO_BIN_EXE_tenma")).args(["--max-requests", "2", "tests/fixtures/fatal.php"]).env("PORT", port.to_string()).stdout(Stdio::piped()).spawn().unwrap();
    let guard = KillOnDrop(server.id());
    get(port, "/");
    assert!(get(port, "/oom").starts_with("HTTP/1.1 500"));
    assert!(get(port, "/").ends_with("request 1\n"));
    assert!(get(port, "/").ends_with("request 2\n"));
    Command::new("kill").args(["-TERM", &server.id().to_string()]).status().unwrap();
    let output = server.wait_with_output().unwrap();
    std::mem::forget(guard);
    // Start, restart after the fatal error, restart after two more requests
    assert_eq!(String::from_utf8_lossy(&output.stdout).matches("start\n").count(), 3);
}

#[test]
fn prints_its_version() {
    let output = Command::new(env!("CARGO_BIN_EXE_tenma")).arg("--version").output().unwrap();
    let version = String::from_utf8_lossy(&output.stdout);
    assert!(output.status.success() && version.starts_with(concat!("tenma ", env!("CARGO_PKG_VERSION"), " (PHP 8.")), "{version}");
    // The extension reports the same version to PHP
    let output = Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/version.php").output().unwrap();
    assert_eq!(String::from_utf8_lossy(&output.stdout), env!("CARGO_PKG_VERSION"));
}

#[test]
fn nonsensical_options_are_rejected() {
    for args in [["--max-body", "99999999999999999999g"], ["--max-body", "17179869184g"], ["--workers", "0"]] {
        let output = Command::new(env!("CARGO_BIN_EXE_tenma")).args(args).arg("tests/fixtures/hello.php").output().unwrap();
        assert_eq!(output.status.code(), Some(2), "{args:?}");
    }
}

#[test]
fn timers_and_background_work_like_in_node() {
    // A script without a server runs until nothing is pending, then tenma exits
    let started = Instant::now();
    let output = Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/timers.php").output().unwrap();
    assert!(output.status.success(), "{output:?}");
    assert_eq!(String::from_utf8_lossy(&output.stdout), "script done\nbackground done\nticks 3\nnested\n");
    assert!(started.elapsed() < Duration::from_secs(2), "{:?}", started.elapsed());
}

#[test]
fn exit_and_errors_outside_requests() {
    let run = |script: &str| {
        let started = Instant::now();
        let output = Command::new(env!("CARGO_BIN_EXE_tenma")).arg(format!("tests/fixtures/scripts/{script}")).env("PORT", "18112").output().unwrap();
        (output.status.code(), String::from_utf8_lossy(&output.stdout).into_owned(), started.elapsed())
    };
    let (code, _, took) = run("exit_in_timer.php");
    assert_eq!(code, Some(3));
    assert!(took < Duration::from_secs(2));
    let (code, stdout, took) = run("die_at_start.php");
    assert_eq!((code, stdout.as_str()), (Some(0), "Usage: die_at_start.php <file>\n"));
    assert!(took < Duration::from_secs(1));
    let (code, _, took) = run("fatal_in_background.php");
    assert_eq!(code, Some(255));
    assert!(took < Duration::from_secs(2));
    let (code, _, took) = run("fatal_at_server_start.php");
    assert_eq!(code, Some(255));
    assert!(took < Duration::from_secs(2));
    for _ in 0..8 {
        assert_eq!(run("exit_async.php").0, Some(3));
    }
    let (code, stdout, _) = run("bad_delay.php");
    assert_eq!((code, stdout.as_str()), (Some(0), "rejected\nrejected\nrejected\n"));
}

#[test]
fn file_system_async() {
    let output = Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/scripts/fs.php").output().unwrap();
    assert!(output.status.success(), "{output:?}");
    assert_eq!(
        String::from_utf8_lossy(&output.stdout),
        "[true,21,[\"a.txt\",\"sub\"],[\"c.txt\",\"dir\"],[21,true,false,true],true,false]\n\
         2 with path\n17 with path\n22 with path 21\nbytes kept\nchdir applies\ncleaned up\n"
    );
    let allowed = format!("open_basedir={}/tests", env!("CARGO_MANIFEST_DIR"));
    let basedir = Command::new(env!("CARGO_BIN_EXE_tenma")).args(["-d", &allowed, "tests/fixtures/scripts/fs_basedir.php"]).output().unwrap();
    assert_eq!(String::from_utf8_lossy(&basedir.stdout), "forbidden\n");
}

#[test]
fn exit_status_is_not_stale() {
    let port = 18_113;
    let mut server = Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/scripts/exit_status_stale.php").env("PORT", port.to_string()).spawn().unwrap();
    assert!(get(port, "/exit5").starts_with("HTTP/1.1 500"));
    assert!(get(port, "/").ends_with("ok\n"));
    assert_eq!(server.wait().unwrap().code(), Some(0));
}

#[test]
fn forms_at_their_limits() {
    let port = 18_138;
    // 8 MB in one text field: the parser once needed ten times that, now it fits next to the body
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).args(["-d", "memory_limit=48M", "tests/fixtures/request_response.php"]).env("PORT", port.to_string()).spawn().unwrap());
    let boundary = "tenmaboundary";
    let body = format!("--{boundary}\r\nContent-Disposition: form-data; name=\"blob\"\r\n\r\n{}\r\n--{boundary}--\r\n", "x".repeat(8 << 20));
    let response = request(
        port,
        &format!("POST /form-size HTTP/1.1\r\nHost: localhost\r\nContent-Type: multipart/form-data; boundary={boundary}\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}", body.len()),
    );
    assert!(response.contains(r#""blob": 8388608"#), "{response}");

    // Exactly as many parts as allowed: the closing boundary is not one of them
    let form = |parts: usize| (1..=parts).map(|i| format!("--{boundary}\r\nContent-Disposition: form-data; name=\"f{i}\"\r\n\r\n{i}\r\n")).collect::<String>() + &format!("--{boundary}--\r\n");
    let send = |body: String| request(port, &format!("POST /form-size HTTP/1.1\r\nHost: localhost\r\nContent-Type: multipart/form-data; boundary={boundary}\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}", body.len()));
    let full = send(form(1000));
    assert!(full.contains(r#""f1000": 4"#), "{}", &full[..full.len().min(300)]);
    assert!(send(form(1001)).starts_with("HTTP/1.1 500"));
}

#[test]
fn request_and_response_in_fiber_mode() {
    let port = 18_114;
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/request_response.php").env("PORT", port.to_string()).spawn().unwrap());
    get(port, "/");
    let send = |raw: String| request(port, &raw);

    // Cookies like under FPM: the first one wins, names like a[b] and x.y are converted
    let cookies = send("GET /cookies HTTP/1.1\r\nHost: x\r\nCookie: sid=first; sid=second; a[b]=1; x.y=2\r\nConnection: close\r\n\r\n".into());
    assert!(cookies.contains("\"sid\": \"first\"") && cookies.contains("\"b\": \"1\"") && cookies.contains("\"x_y\": \"2\""), "{cookies}");

    let urlencoded = "a=1&list[]=x&list[]=y&deep[k]=v";
    let form = send(format!("POST /form HTTP/1.1\r\nHost: x\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{urlencoded}", urlencoded.len()));
    assert!(form.contains("\"list\": [\n            \"x\",\n            \"y\"\n        ]") && form.contains("\"k\": \"v\""), "{form}");

    // Multipart: fields with PHP's semantics, files with names without directory, several under name[]
    let boundary = "boundary123";
    let body = format!(
        "--{boundary}\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nImage\r\n\
         --{boundary}\r\nContent-Disposition: form-data; name=\"tags[]\"\r\n\r\none\r\n\
         --{boundary}\r\nContent-Disposition: form-data; name=\"tags[]\"\r\n\r\ntwo\r\n\
         --{boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"../../etc/hello.txt\"\r\nContent-Type: text/plain\r\n\r\nHello\r\nMars\r\n\
         --{boundary}\r\nContent-Disposition: form-data; name=\"many[]\"; filename=\"a.bin\"\r\n\r\nA\r\n\
         --{boundary}\r\nContent-Disposition: form-data; name=\"many[]\"; filename=\"b.bin\"\r\n\r\nB\r\n\
         --{boundary}\r\nContent-Disposition: form-data; name=\"empty\"; filename=\"\"\r\n\r\n\r\n\
         --{boundary}\r\nContent-Disposition: form-data; name=\"nameless\"; filename=\"\"\r\n\r\ndata\r\n\
         --{boundary}--\r\n"
    );
    let multipart = |path: &str| send(format!("POST {path} HTTP/1.1\r\nHost: x\r\nContent-Type: multipart/form-data; boundary={boundary}\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{body}", body.len()));
    let parsed = multipart("/form");
    assert!(parsed.contains("\"title\": \"Image\"") && parsed.contains("\"tags\": [\n            \"one\",\n            \"two\"\n        ]"), "{parsed}");
    assert!(parsed.contains("\"hello.txt\",\n            \"text\\/plain\",\n            11,\n            \"Hello\\r\\nMars\""), "{parsed}");
    assert!(parsed.contains("\"a.bin\"") && parsed.contains("\"b.bin\"") && !parsed.contains("\"empty\"") && !parsed.contains("\"nameless\""), "{parsed}");
    assert!(multipart("/move").ends_with("\r\n\r\nHello\r\nMars"));

    // Response helpers: redirect, two cookies, one header with two values
    let set = get(port, "/set-cookie");
    assert!(set.starts_with("HTTP/1.1 303") && set.contains("location: /target"), "{set}");
    assert!(set.contains("set-cookie: sid=a%20b%3Bc; Path=/app; HttpOnly; SameSite=Lax"), "{set}");
    assert!(set.contains("set-cookie: theme=dark; Expires=Wed, 18 May 2033 03:33:20 GMT; Max-Age=") && set.contains("; Path=/; Secure; HttpOnly; SameSite=Strict"), "{set}");
    assert!(set.contains("x-two: one") && set.contains("x-two: two"));
    // A control character in a header fails where it is set, or as a 500 instead of a lost header
    assert!(get(port, "/header-rejected").ends_with("Invalid header: content-disposition"));
    for value in ["a%1Fb", "a%00b"] {
        let response = get(port, &format!("/header-in-array?value={value}"));
        assert!(response.starts_with("HTTP/1.1 500") && !response.contains("never sent"), "{response}");
    }
    assert!(get(port, "/header-in-array?value=a%09b").contains("x-name: a\tb"));
    let html = get(port, "/html");
    assert!(html.starts_with("HTTP/1.1 201") && html.contains("content-type: text/html; charset=utf-8") && html.ends_with("<h1>Hello</h1>"));
}

#[test]
fn static_files() {
    let port = 18_115;
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/static.php").env("PORT", port.to_string()).spawn().unwrap());
    get(port, "/start");
    let send = |raw: &str| request(port, raw);

    let css = get(port, "/style.css");
    assert!(css.starts_with("HTTP/1.1 200") && css.contains("content-type: text/css; charset=utf-8") && css.ends_with("color: red; }\n"), "{css}");
    assert!(css.contains("x-content-type-options: nosniff"), "{css}");
    // An SVG someone uploaded runs no script on the app's origin when opened directly
    let svg = get(port, "/upload.svg");
    assert!(svg.contains("content-type: image/svg+xml") && svg.contains("content-security-policy: sandbox"), "{svg}");
    assert!(get(port, "/").ends_with("<h1>Start</h1>\n"));
    assert!(get(port, "/missing.css").ends_with("php: /missing.css\n"));

    // Never served: hidden files, ways out of the directory, symlinks to the outside
    for path in ["/.env", "/../secret.txt", "/%2e%2e/secret.txt", "/sub/..%2f..%2fsecret.txt", "/link.txt", "/front.php", "/front.PHP"] {
        let response = get(port, path);
        assert!(response.contains("php: ") && !response.contains("SECRET") && !response.contains("secret\n"), "{path}: {response}");
    }

    let etag = css.lines().find_map(|line| line.strip_prefix("etag: ")).unwrap();
    let cached = send(&format!("GET /style.css HTTP/1.1\r\nHost: x\r\nIf-None-Match: {etag}\r\nConnection: close\r\n\r\n"));
    assert!(cached.starts_with("HTTP/1.1 304"), "{cached}");

    let part = send("GET /sub/digits.txt HTTP/1.1\r\nHost: x\r\nRange: bytes=2-4\r\nConnection: close\r\n\r\n");
    assert!(part.starts_with("HTTP/1.1 206") && part.contains("content-range: bytes 2-4/10") && part.ends_with("\r\n\r\n234"), "{part}");
    let suffix = send("GET /sub/digits.txt HTTP/1.1\r\nHost: x\r\nRange: bytes=-3\r\nConnection: close\r\n\r\n");
    assert!(suffix.ends_with("\r\n\r\n789"), "{suffix}");
    let outside = send("GET /sub/digits.txt HTTP/1.1\r\nHost: x\r\nRange: bytes=20-\r\nConnection: close\r\n\r\n");
    assert!(outside.starts_with("HTTP/1.1 416"), "{outside}");

    let head = send("HEAD /style.css HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
    assert!(head.contains("content-length: 21") && head.ends_with("\r\n\r\n"), "{head}");
    // A symlink in the directory to a hidden file in it stays hidden
    assert!(get(port, "/config.txt").contains("php: /config.txt"));
    // A directory without / is redirected, without index.html it goes to PHP
    let directory = get(port, "/sub?a=1");
    assert!(directory.starts_with("HTTP/1.1 301") && directory.contains("location: /sub/?a=1"), "{directory}");
    assert!(get(port, "/sub/").ends_with("php: /sub/\n"));
    // If-Range no longer matches: the whole file instead of a piece
    let stale = send("GET /sub/digits.txt HTTP/1.1\r\nHost: x\r\nRange: bytes=2-4\r\nIf-Range: \"old\"\r\nConnection: close\r\n\r\n");
    assert!(stale.starts_with("HTTP/1.1 200") && stale.ends_with("0123456789"), "{stale}");
    // Invalid ranges are ignored, empty files arrive whole
    for value in ["bytes=5-3", "bytes=abc-", "bytes=+1-+3"] {
        let response = send(&format!("GET /sub/digits.txt HTTP/1.1\r\nHost: x\r\nRange: {value}\r\nConnection: close\r\n\r\n"));
        assert!(response.starts_with("HTTP/1.1 200") && response.ends_with("0123456789"), "{value}: {response}");
    }
    let empty = send("GET /empty.txt HTTP/1.1\r\nHost: x\r\nRange: bytes=0-\r\nConnection: close\r\n\r\n");
    assert!(empty.starts_with("HTTP/1.1 200") && empty.contains("content-length: 0"), "{empty}");
    // POST always goes to PHP
    assert!(send("POST /style.css HTTP/1.1\r\nHost: x\r\nContent-Length: 0\r\nConnection: close\r\n\r\n").ends_with("php: /style.css\n"));
}

#[test]
fn multipart_edge_cases() {
    let output = Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/scripts/multipart_edge.php").output().unwrap();
    assert_eq!(
        String::from_utf8_lossy(&output.stdout),
        "{\"f\":\"X\"}\n{\"real\":\"a;name=evil.txt\",\"path\":\"x41.txt\",\"doc\":{\"a\":\"d.txt\"},\"x\":[\"x2\"]}\n\
         Too many parts in the form frugal\nrejected\nrejected\nrejected\nrejected\n\"s=v; Path=\\/; HttpOnly; SameSite=Lax\"\n\"s=v; Path=\\/; Secure; HttpOnly; SameSite=None\"\n"
    );
}

#[test]
fn static_files_with_open_basedir() {
    let allowed = format!("open_basedir={}/tests/fixtures/scripts", env!("CARGO_MANIFEST_DIR"));
    let output = Command::new(env!("CARGO_BIN_EXE_tenma")).args(["-d", &allowed, "tests/fixtures/scripts/static_basedir.php"]).output().unwrap();
    assert_eq!(String::from_utf8_lossy(&output.stdout), "forbidden\n");
}

#[test]
fn client_gone_cancels_handler() {
    let port = 18_117;
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).args(["--workers", "1", "tests/fixtures/gone.php"]).env("PORT", port.to_string()).spawn().unwrap());
    get(port, "/status");
    let mut stream = TcpStream::connect(("127.0.0.1", port)).unwrap();
    stream.write_all(b"GET /long HTTP/1.1\r\nHost: x\r\n\r\n").unwrap();
    std::thread::sleep(Duration::from_millis(200));
    drop(stream);
    std::thread::sleep(Duration::from_millis(500));
    let status = get(port, "/status");
    assert!(status.contains("cancelled after 0.") && status.contains("The client closed the connection | - | detach ran"), "{status}");
    assert!(get(port, "/cancelled-task").starts_with("HTTP/1.1 500"));
}

#[test]
fn static_files_under_prefixes() {
    let port = 18_118;
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/static_prefix.php").env("PORT", port.to_string()).spawn().unwrap());
    get(port, "/start");
    assert!(get(port, "/assets/style.css").ends_with("color: red; }\n"));
    // The longest prefix wins
    assert!(get(port, "/assets/deep/digits.txt").ends_with("\r\n\r\n0123456789"));
    assert!(get(port, "/robots.txt").ends_with("\r\n\r\n0123456789"));
    let directory = get(port, "/assets");
    assert!(directory.starts_with("HTTP/1.1 301") && directory.contains("location: /assets/"), "{directory}");
    // Outside the prefixes, just next to them or hidden: PHP
    for path in ["/style.css", "/assetsx/style.css", "/robots.txt/x", "/assets/.env", "/assets/missing.css"] {
        assert!(get(port, path).ends_with(&format!("php: {path}\n")), "{path}");
    }
}

#[test]
fn async_local_travels_with_tasks() {
    let output = Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/scripts/async_local.php").output().unwrap();
    let stdout = String::from_utf8_lossy(&output.stdout);
    assert!(stdout.contains("timer a: a\n") && stdout.contains("timer b: b\n"), "{stdout}");
    assert!(stdout.ends_with("[[\"a\",\"a\",\"a\",null],[\"b\",\"b\",\"b\",null],\"script\"]\n"), "{stdout}");
}

#[test]
fn stubs_describe_the_public_api_only() {
    let output = Command::new(env!("CARGO_BIN_EXE_tenma")).arg("--stubs").output().unwrap();
    assert!(output.status.success(), "{output:?}");
    let stubs = String::from_utf8_lossy(&output.stdout);
    assert!(stubs.starts_with("<?php\n"), "{stubs}");
    for expected in ["final class Tenma", "public static function serve(int $port, callable $handler", "public static function async(callable $work): \\Tenma\\Task {}", "namespace Tenma\\Http {", "final class Request", "final class AsyncLocal", "public static function readFile(string $path): string {}"] {
        assert!(stubs.contains(expected), "missing {expected}");
    }
    for hidden in ["Tenma\\Internal", "@internal", "class Multipart", "function call("] {
        assert!(!stubs.contains(hidden), "leaks {hidden}");
    }
}

/// A fresh directory for scripts a test changes.
fn scratch(name: &str) -> std::path::PathBuf {
    let directory = std::env::temp_dir().join(format!("tenma-{name}-{}", std::process::id()));
    let _ = std::fs::remove_dir_all(&directory);
    std::fs::create_dir_all(&directory).unwrap();
    directory
}

/// Asks until the response satisfies `expected`; a reload takes a moment.
fn eventually(port: u16, path: &str, expected: impl Fn(&str) -> bool) -> String {
    let started = Instant::now();
    loop {
        let response = get(port, path);
        if expected(&response) || started.elapsed() > Duration::from_secs(5) {
            return response;
        }
        std::thread::sleep(Duration::from_millis(50));
    }
}

#[test]
fn watch_reloads_on_changes() {
    let port = 18_119;
    let directory = scratch("watch");
    let app = directory.join("app.php");
    let lazy = directory.join("lazy.php");
    let script = |version: &str| format!("<?php\nTenma::serve({port}, function ($request) {{\n    if ($request->path === '/lazy') {{ require_once __DIR__ . '/lazy.php'; return lazy(); }}\n    return \"{version}\\n\";\n}});\n");
    std::fs::write(&app, script("one")).unwrap();
    std::fs::write(&lazy, "<?php function lazy() { return \"lazy one\\n\"; }").unwrap();
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).args(["--watch", "--workers", "2"]).arg(&app).spawn().unwrap());
    assert!(get(port, "/").ends_with("\r\n\r\none\n"));
    assert!(get(port, "/lazy").ends_with("lazy one\n"));
    // The watcher gets the loaded files from the workers; FSEvents needs a moment for a new directory
    std::thread::sleep(Duration::from_millis(200));

    std::fs::write(&app, script("two")).unwrap();
    let response = eventually(port, "/", |response| response.ends_with("two\n"));
    assert!(response.ends_with("\r\n\r\ntwo\n"), "{response}");
    // Loaded only by a request, after the start: watched as well
    std::fs::write(&lazy, "<?php function lazy() { return \"lazy two\\n\"; }").unwrap();
    let response = eventually(port, "/lazy", |response| response.ends_with("lazy two\n"));
    assert!(response.ends_with("lazy two\n"), "{response}");

    // A syntax error does not end tenma: 503 until the next change
    std::fs::write(&app, "<?php syntax error").unwrap();
    let response = eventually(port, "/", |response| response.starts_with("HTTP/1.1 503"));
    assert!(response.starts_with("HTTP/1.1 503") && response.contains("Waiting for changes"), "{response}");
    std::fs::write(&app, script("three")).unwrap();
    let response = eventually(port, "/", |response| response.ends_with("three\n"));
    assert!(response.ends_with("\r\n\r\nthree\n"), "{response}");

    // No server for a while, but an interval keeps the script running: 503 from every worker, and
    // every worker serves again after the next change
    std::fs::write(&app, "<?php\nTenma::setInterval(fn () => null, 1);\n").unwrap();
    let response = eventually(port, "/", |response| response.starts_with("HTTP/1.1 503"));
    assert!(response.contains("serves no requests"), "{response}");
    for _ in 0..4 {
        assert!(get(port, "/").starts_with("HTTP/1.1 503"));
    }
    std::fs::write(&app, script("four")).unwrap();
    eventually(port, "/", |response| response.ends_with("four\n"));
    for _ in 0..4 {
        assert!(get(port, "/").ends_with("\r\n\r\nfour\n"));
    }
    let _ = std::fs::remove_dir_all(directory);
}

#[test]
fn watch_reruns_scripts_without_servers() {
    use std::io::BufRead;
    let directory = scratch("watch-script");
    let script = directory.join("script.php");
    std::fs::write(&script, "<?php echo \"run one\\n\";").unwrap();
    let mut child = Command::new(env!("CARGO_BIN_EXE_tenma")).arg("--watch").arg(&script).stdout(std::process::Stdio::piped()).spawn().unwrap();
    let mut lines = std::io::BufReader::new(child.stdout.take().unwrap()).lines();
    let _child = Server(child);
    assert_eq!(lines.next().unwrap().unwrap(), "run one");
    // The script has ended; tenma waits for a change and runs it again
    std::thread::sleep(Duration::from_millis(200));
    // Still running with a pending timer: a change stops it and runs the script again
    std::fs::write(&script, "<?php echo \"run two\\n\"; Tenma::setTimeout(fn() => null, 60_000);").unwrap();
    assert_eq!(lines.next().unwrap().unwrap(), "run two");
    std::thread::sleep(Duration::from_millis(200));
    std::fs::write(&script, "<?php echo \"run three\\n\";").unwrap();
    // On a busy machine the watcher may see the half-written file and then the whole one: run two
    // can come twice, but must be followed by run three
    let next = lines.find(|line| line.as_deref().map_or(true, |line| line != "run two")).unwrap().unwrap();
    assert_eq!(next, "run three");
    let _ = std::fs::remove_dir_all(directory);
}

/// A project with two hooks, listed against their order: a command copies the CSS, PHP minifies
/// the copy and counts its runs.
fn hook_project(name: &str) -> std::path::PathBuf {
    let directory = scratch(name);
    std::fs::create_dir_all(directory.join("assets")).unwrap();
    std::fs::create_dir_all(directory.join("public")).unwrap();
    std::fs::write(directory.join("assets/app.css"), "a { }").unwrap();
    std::fs::write(directory.join("copy.sh"), "#!/bin/sh\necho \"copying $1\"\ncat \"$1\" > \"$2\"\n").unwrap();
    std::fs::set_permissions(directory.join("copy.sh"), std::os::unix::fs::PermissionsExt::from_mode(0o755)).unwrap();
    std::fs::write(directory.join("tenma.dev.php"), minify_hooks("")).unwrap();
    // Loaded only when the hook runs
    std::fs::write(directory.join("transform.php"), "<?php function transform($css) { return $css; }").unwrap();
    directory
}

fn minify_hooks(suffix: &str) -> String {
    format!(
        "<?php\nreturn [\n    new Tenma\\Hook(inputs: ['public/app.css'], outputs: ['public/app.min.css'], name: 'minify', run: function (array $changed) {{\n        require_once __DIR__ . '/transform.php';\n        file_put_contents(__DIR__ . '/runs.log', 'minify ' . implode(',', array_map('basename', $changed)) . \"\\n\", FILE_APPEND);\n        file_put_contents(__DIR__ . '/public/app.min.css', transform(str_replace(' ', '', file_get_contents(__DIR__ . '/public/app.css'))) . '{suffix}');\n    }}),\n    new Tenma\\Hook(inputs: ['assets/**/*.css'], outputs: ['public/app.css'], run: ['./copy.sh', 'assets/app.css', 'public/app.css'], name: 'css'),\n];\n"
    )
}

#[test]
fn build_runs_hooks_in_order_and_fails_on_cycles() {
    let directory = hook_project("build");
    let output = Command::new(env!("CARGO_BIN_EXE_tenma")).arg("build").current_dir(&directory).output().unwrap();
    assert!(output.status.success(), "{output:?}");
    assert_eq!(String::from_utf8_lossy(&output.stdout), "[css] copying assets/app.css\n");
    assert_eq!(std::fs::read_to_string(directory.join("public/app.min.css")).unwrap(), "a{}");
    assert_eq!(std::fs::read_to_string(directory.join("runs.log")).unwrap(), "minify app.css\n");

    std::fs::write(directory.join("cycle.php"), "<?php return [\n    new Tenma\\Hook(inputs: ['b'], outputs: ['a'], run: fn () => null, name: 'a'),\n    new Tenma\\Hook(inputs: ['a'], outputs: ['b'], run: fn () => null, name: 'b'),\n];").unwrap();
    let output = Command::new(env!("CARGO_BIN_EXE_tenma")).args(["build", "cycle.php"]).current_dir(&directory).output().unwrap();
    assert_eq!(output.status.code(), Some(1));
    assert!(String::from_utf8_lossy(&output.stderr).contains("form a cycle"), "{output:?}");

    std::fs::write(directory.join("fails.php"), "<?php return [new Tenma\\Hook(inputs: [], run: ['false'])];").unwrap();
    let output = Command::new(env!("CARGO_BIN_EXE_tenma")).args(["build", "fails.php"]).current_dir(&directory).output().unwrap();
    assert_eq!(output.status.code(), Some(1));
    assert!(String::from_utf8_lossy(&output.stderr).contains("tenma: hook false failed: exited with status 1"), "{output:?}");
    let _ = std::fs::remove_dir_all(directory);
}

#[test]
fn watch_runs_hooks_on_changes() {
    let port = 18_121;
    let directory = hook_project("hooks");
    std::fs::write(directory.join("app.php"), format!("<?php Tenma::serve({port}, fn () => file_get_contents(__DIR__ . '/public/app.min.css'));")).unwrap();
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).args(["--watch", "app.php"]).current_dir(&directory).stdout(std::process::Stdio::null()).spawn().unwrap());
    // The hooks ran before the first request
    assert!(get(port, "/").ends_with("\r\n\r\na{}"));

    std::fs::write(directory.join("assets/app.css"), "b { }").unwrap();
    let response = eventually(port, "/", |response| response.ends_with("b{}"));
    assert!(response.ends_with("\r\n\r\nb{}"), "{response}");
    // The same content again: the copy does not change, minify does not run
    std::fs::write(directory.join("assets/app.css"), "b { }").unwrap();
    std::thread::sleep(Duration::from_millis(600));
    assert_eq!(std::fs::read_to_string(directory.join("runs.log")).unwrap(), "minify app.css\nminify app.css\n");

    // A file a hook loaded as it ran reloads the hooks, which run again; so does tenma.dev.php
    std::fs::write(directory.join("transform.php"), "<?php function transform($css) { return strtoupper($css); }").unwrap();
    let response = eventually(port, "/", |response| response.ends_with("B{}"));
    assert!(response.ends_with("\r\n\r\nB{}"), "{response}");
    std::fs::write(directory.join("tenma.dev.php"), minify_hooks("/* dev */")).unwrap();
    let response = eventually(port, "/", |response| response.ends_with("B{}/* dev */"));
    assert!(response.ends_with("\r\n\r\nB{}/* dev */"), "{response}");
    let _ = std::fs::remove_dir_all(directory);
}

#[test]
fn watch_stops_hooks_that_trigger_themselves() {
    use std::io::BufRead;
    let directory = scratch("brake");
    std::fs::create_dir_all(directory.join("src")).unwrap();
    std::fs::write(directory.join("app.php"), "<?php").unwrap();
    // Writes into its own inputs without declaring it
    std::fs::write(directory.join("tenma.dev.php"), "<?php return [new Tenma\\Hook(inputs: ['src/*.txt'], name: 'loop', run: fn () => file_put_contents(__DIR__ . '/src/self.txt', microtime()))];").unwrap();
    let mut child = Command::new(env!("CARGO_BIN_EXE_tenma")).args(["--watch", "app.php"]).current_dir(&directory).stderr(std::process::Stdio::piped()).spawn().unwrap();
    let mut lines = std::io::BufReader::new(child.stderr.take().unwrap()).lines().map_while(Result::ok);
    let _child = Server(child);
    // The script has run, so the hooks have too and their directories are watched
    assert!(lines.any(|line| line.contains("waiting for changes")));
    // FSEvents needs a moment for a new directory
    std::thread::sleep(Duration::from_millis(300));
    std::fs::write(directory.join("src/start.txt"), "go").unwrap();
    let stopped = lines.any(|line| line.contains("hook loop keeps triggering itself, stopped"));
    assert!(stopped);
    let _ = std::fs::remove_dir_all(directory);
}

#[test]
fn watch_keeps_hook_roots_recursive_when_the_app_loads_from_them() {
    use std::io::BufRead;
    let directory = scratch("recursive");
    std::fs::create_dir_all(directory.join("src/sub")).unwrap();
    // The app loads a file from the hook's root: its directory must stay watched recursively
    std::fs::write(directory.join("src/a.php"), "<?php").unwrap();
    std::fs::write(directory.join("app.php"), "<?php require __DIR__ . '/src/a.php'; echo \"loaded\\n\";").unwrap();
    std::fs::write(directory.join("tenma.dev.php"), "<?php return [new Tenma\\Hook(inputs: ['src/**/*.txt'], name: 'txt', run: fn (array $changed) => print('changed ' . implode(',', array_map('basename', $changed)) . \"\\n\"))];").unwrap();
    let mut child = Command::new(env!("CARGO_BIN_EXE_tenma")).args(["--watch", "app.php"]).current_dir(&directory).stdout(std::process::Stdio::piped()).stderr(std::process::Stdio::null()).spawn().unwrap();
    let stdout = child.stdout.take().unwrap();
    let _child = Server(child);
    let (sender, lines) = std::sync::mpsc::channel();
    std::thread::spawn(move || std::io::BufReader::new(stdout).lines().map_while(Result::ok).for_each(|line| drop(sender.send(line))));
    let wait_for = |expected: &str| loop {
        match lines.recv_timeout(Duration::from_secs(5)) {
            Ok(line) if line == expected => break,
            Ok(_) => {}
            Err(_) => panic!("no {expected:?}"),
        }
    };
    wait_for("loaded");
    std::thread::sleep(Duration::from_millis(300));
    std::fs::write(directory.join("src/sub/deep.txt"), "x").unwrap();
    wait_for("changed deep.txt");
    let _ = std::fs::remove_dir_all(directory);
}

/// A minimal DAP client: requests with a Content-Length header, answers in order.
struct Dap {
    stream: TcpStream,
    seq: u64,
    /// Output events that came while waiting for something else.
    output: Vec<serde_json::Value>,
}

impl Dap {
    fn connect(port: u16) -> Dap {
        let started = Instant::now();
        loop {
            match TcpStream::connect(("127.0.0.1", port)) {
                Ok(stream) => {
                    stream.set_read_timeout(Some(Duration::from_secs(5))).unwrap();
                    return Dap { stream, seq: 0, output: Vec::new() };
                }
                Err(_) if started.elapsed() < Duration::from_secs(5) => std::thread::sleep(Duration::from_millis(20)),
                Err(error) => panic!("debugger does not respond: {error}"),
            }
        }
    }

    fn send(&mut self, command: &str, arguments: serde_json::Value) {
        self.seq += 1;
        let body = serde_json::json!({"seq": self.seq, "type": "request", "command": command, "arguments": arguments}).to_string();
        write!(self.stream, "Content-Length: {}\r\n\r\n{body}", body.len()).unwrap();
    }

    fn read(&mut self) -> serde_json::Value {
        let mut header = Vec::new();
        while !header.ends_with(b"\r\n\r\n") {
            let mut byte = [0];
            self.stream.read_exact(&mut byte).unwrap();
            header.push(byte[0]);
        }
        let length: usize = String::from_utf8_lossy(&header).trim().trim_start_matches("Content-Length:").trim().parse().unwrap();
        let mut body = vec![0; length];
        self.stream.read_exact(&mut body).unwrap();
        serde_json::from_slice(&body).unwrap()
    }

    /// The next message of this kind ("response" or "event") and name, skipping others.
    fn expect(&mut self, kind: &str, name: &str) -> serde_json::Value {
        if (kind, name) == ("event", "output") && !self.output.is_empty() {
            return self.output.remove(0);
        }
        loop {
            let message = self.read();
            let key = if kind == "event" { &message["event"] } else { &message["command"] };
            if message["type"] == kind && key == name {
                return message;
            }
            if message["event"] == "output" {
                self.output.push(message);
            }
        }
    }

    fn request(&mut self, command: &str, arguments: serde_json::Value) -> serde_json::Value {
        self.send(command, arguments);
        let response = self.expect("response", command);
        assert_eq!(response["success"], true, "{response}");
        response["body"].clone()
    }

    /// Variables as name, value and the reference to expand them (0: nothing to expand).
    /// The reference of $point among the frame's locals.
    fn stopped_locals(&mut self, frame: &serde_json::Value) -> (String, u64) {
        let locals = self.request("scopes", serde_json::json!({"frameId": frame}))["scopes"][0]["variablesReference"].as_u64().unwrap();
        self.variables(locals).into_iter().find(|(name, _, _)| name == "$point").map(|(_, value, reference)| (value, reference)).unwrap()
    }

    fn variables(&mut self, reference: u64) -> Vec<(String, String, u64)> {
        let body = self.request("variables", serde_json::json!({"variablesReference": reference}));
        body["variables"].as_array().unwrap().iter().map(|variable| (variable["name"].as_str().unwrap().to_owned(), variable["value"].as_str().unwrap().to_owned(), variable["variablesReference"].as_u64().unwrap())).collect()
    }

    /// Waits for the stop: its event, then the innermost frame's function and line and its locals.
    fn stopped(&mut self) -> Stop {
        let event = self.expect("event", "stopped")["body"].clone();
        let thread = event["threadId"].as_u64().unwrap();
        let frames = self.request("stackTrace", serde_json::json!({"threadId": thread}));
        let top = frames["stackFrames"][0].clone();
        let scopes = self.request("scopes", serde_json::json!({"frameId": top["id"]}));
        let locals = self.variables(scopes["scopes"][0]["variablesReference"].as_u64().unwrap());
        Stop { event, function: top["name"].as_str().unwrap().to_owned(), line: top["line"].as_u64().unwrap(), locals }
    }

    fn start(debugger: u16) -> Dap {
        let mut dap = Dap::connect(debugger);
        dap.request("initialize", serde_json::json!({"adapterID": "tenma"}));
        dap.expect("event", "initialized");
        dap
    }

    fn break_at(&mut self, file: &std::path::Path, lines: &[u64]) {
        let path = std::fs::canonicalize(file).unwrap();
        let breakpoints: Vec<_> = lines.iter().map(|line| serde_json::json!({"line": line})).collect();
        let set = self.request("setBreakpoints", serde_json::json!({"source": {"path": path}, "breakpoints": breakpoints}));
        assert_eq!(set["breakpoints"].as_array().unwrap().len(), lines.len());
    }

    fn command(&mut self, command: &str) {
        self.request(command, serde_json::json!({"threadId": 1}));
    }
}

struct Stop {
    event: serde_json::Value,
    function: String,
    line: u64,
    locals: Vec<(String, String, u64)>,
}

impl Stop {
    fn local(&self, name: &str) -> Option<(String, u64)> {
        self.locals.iter().find(|(found, ..)| found == name).map(|(_, value, reference)| (value.clone(), *reference))
    }
}

fn inspected(port: u16, debugger: u16, option: &str) -> Server {
    Server(Command::new(env!("CARGO_BIN_EXE_tenma")).args([&format!("{option}={debugger}"), "tests/fixtures/inspect.php"]).env("PORT", port.to_string()).spawn().unwrap())
}

#[test]
fn inspect_without_an_editor_never_stops() {
    let (port, debugger) = (18_130, 19_236);
    let _server = inspected(port, debugger, "--inspect");
    // An uncaught exception would stop by default: without an editor nobody could resume it
    let started = Instant::now();
    assert!(get(port, "/throw").starts_with("HTTP/1.1 500"));
    assert!(started.elapsed() < Duration::from_secs(2), "{:?}", started.elapsed());

    // A message length nobody could send: the session ends, tenma and the next editor carry on
    for length in ["18446744073709551615", "4611686018427387904"] {
        let mut stream = TcpStream::connect(("127.0.0.1", debugger)).unwrap();
        stream.set_read_timeout(Some(Duration::from_secs(3))).unwrap();
        stream.write_all(format!("Content-Length: {length}\r\n\r\n").as_bytes()).unwrap();
        let _ = stream.read_to_end(&mut Vec::new());
        // With a timeout: a worker stopped for a debugger that is gone would never answer
        let mut stream = TcpStream::connect(("127.0.0.1", port)).unwrap();
        stream.set_read_timeout(Some(Duration::from_secs(3))).unwrap();
        stream.write_all(b"GET /throw HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n").unwrap();
        let mut response = String::new();
        let _ = stream.read_to_string(&mut response);
        assert!(response.starts_with("HTTP/1.1 500"), "after Content-Length {length}: {response:?}");
    }
    // A web page can POST to the debugger port; the browser's request line ends the session
    let message = r#"{"seq":1,"type":"request","command":"initialize","arguments":{"adapterID":"tenma"}}"#;
    let mut stream = TcpStream::connect(("127.0.0.1", debugger)).unwrap();
    stream.set_read_timeout(Some(Duration::from_secs(3))).unwrap();
    stream.write_all(format!("POST / HTTP/1.1\r\nHost: 127.0.0.1:{debugger}\r\nContent-Type: text/plain\r\nContent-Length: {}\r\n\r\n{message}", message.len()).as_bytes()).unwrap();
    let mut answer = String::new();
    let _ = stream.read_to_string(&mut answer);
    assert_eq!(answer, "");
    Dap::start(debugger);
}

#[test]
fn inspect_stops_at_breakpoints_and_steps() {
    let (port, debugger) = (18_122, 19_229);
    let _server = inspected(port, debugger, "--inspect");
    let mut dap = Dap::start(debugger);
    dap.break_at("tests/fixtures/inspect.php".as_ref(), &[27]);
    dap.request("configurationDone", serde_json::json!({}));

    let response = std::thread::spawn(move || get(port, "/"));
    let stop = dap.stopped();
    assert_eq!((stop.function.as_str(), stop.line, &stop.event["reason"]), ("{closure:inspect.php:12}", 27, &serde_json::json!("breakpoint")));
    assert_eq!(stop.local("$request").unwrap().0, "Tenma\\Http\\Request");
    assert_eq!(stop.local("$id"), None, "not assigned yet");

    dap.command("next");
    assert_eq!(dap.stopped().local("$id").unwrap().0, "42");
    // Over the constructor, into greet()
    dap.command("next");
    assert_eq!(dap.stopped().line, 29);
    dap.command("stepIn");
    let stop = dap.stopped();
    assert_eq!((stop.function.as_str(), stop.line), ("greet", 4));
    assert_eq!(stop.local("$name").unwrap().0, "'world'");
    // Also in the caller's frame
    let caller = dap.request("stackTrace", serde_json::json!({"threadId": stop.event["threadId"]}))["stackFrames"][1]["id"].clone();
    assert_eq!(dap.request("evaluate", serde_json::json!({"expression": "$id * 2", "frameId": caller}))["result"], "84");
    // Paged like VS Code asks for long stacks; IDs and references stay below 2^31 as DAP wants
    let page = dap.request("stackTrace", serde_json::json!({"threadId": stop.event["threadId"], "startFrame": 1, "levels": 1}));
    assert_eq!(page["stackFrames"].as_array().unwrap().len(), 1);
    assert_eq!(page["stackFrames"][0]["id"], caller);
    assert!(page["totalFrames"].as_u64().unwrap() > 1);
    let scopes = dap.request("scopes", serde_json::json!({"frameId": caller}));
    let locals = scopes["scopes"][0]["variablesReference"].as_u64().unwrap();
    assert!(locals < 1 << 31);
    assert!(dap.variables(locals).iter().any(|(name, _, _)| name == "$id"));
    dap.command("stepOut");
    assert_eq!(dap.stopped().line, 30);
    // Over the sleep: the fiber waits meanwhile, the step ends in the same fiber
    dap.command("next");
    let stop = dap.stopped();
    assert_eq!(stop.line, 31);
    // Objects and arrays expand, private properties included
    let (point, reference) = stop.local("$point").unwrap();
    assert_eq!(point, "Point");
    let properties = dap.variables(reference);
    assert_eq!(properties.iter().map(|(name, value, _)| (name.as_str(), value.as_str())).collect::<Vec<_>>(), [("x", "7"), ("tags", "array(1)")]);
    assert_eq!(dap.variables(properties[1].2), [("a".to_owned(), "1".to_owned(), 0)]);
    dap.command("next");
    assert_eq!(dap.stopped().local("$after").unwrap().0, "'slept'");

    dap.command("continue");
    assert!(response.join().unwrap().ends_with("Hello world slept"));

    // Disconnecting while stopped lets the request finish, the breakpoints are gone
    let response = std::thread::spawn(move || get(port, "/"));
    dap.stopped();
    dap.request("disconnect", serde_json::json!({}));
    assert!(response.join().unwrap().ends_with("Hello world slept"));
    assert!(get(port, "/").ends_with("Hello world slept"));
}

#[test]
fn inspect_shows_requests_as_threads_and_stops_at_exceptions() {
    let (port, debugger) = (18_123, 19_230);
    let _server = inspected(port, debugger, "--inspect");
    let mut dap = Dap::start(debugger);
    dap.break_at("tests/fixtures/inspect.php".as_ref(), &[27]);
    dap.request("configurationDone", serde_json::json!({}));

    // One request waits in Tenma::sleep(), the other stops: both are threads, each with its stack
    let _waiting = std::thread::spawn(move || get(port, "/wait"));
    std::thread::sleep(Duration::from_millis(200));
    let response = std::thread::spawn(move || get(port, "/"));
    let stop = dap.stopped();
    let threads = dap.request("threads", serde_json::json!({}));
    let names: Vec<&str> = threads["threads"].as_array().unwrap().iter().map(|thread| thread["name"].as_str().unwrap()).collect();
    assert_eq!(names, ["GET /", "GET /wait (waiting)"]);
    assert_eq!(threads["threads"][0]["id"], stop.event["threadId"]);
    let waiting = dap.request("stackTrace", serde_json::json!({"threadId": threads["threads"][1]["id"]}));
    assert_eq!(waiting["stackFrames"][0]["line"], 14);
    dap.command("continue");
    assert!(response.join().unwrap().ends_with("Hello world slept"));

    // Uncaught by default, with the frame still alive; caught ones only with "all"
    let response = std::thread::spawn(move || get(port, "/throw"));
    let stop = dap.stopped();
    assert_eq!((stop.line, &stop.event["reason"], &stop.event["description"]), (18, &serde_json::json!("exception"), &serde_json::json!("RuntimeException: boom")));
    // Evaluating while the exception is pending leaves it pending
    let frame = dap.request("stackTrace", serde_json::json!({"threadId": stop.event["threadId"]}))["stackFrames"][0]["id"].clone();
    assert_eq!(dap.request("evaluate", serde_json::json!({"expression": "$request->path", "frameId": frame}))["result"], "'/throw'");
    dap.command("continue");
    assert!(response.join().unwrap().starts_with("HTTP/1.1 500"));
    assert!(get(port, "/caught").ends_with("caught"));
    dap.request("setExceptionBreakpoints", serde_json::json!({"filters": ["all"]}));
    let response = std::thread::spawn(move || get(port, "/caught"));
    let stop = dap.stopped();
    assert_eq!((stop.line, &stop.event["text"]), (22, &serde_json::json!("Exception")));
    dap.command("continue");
    assert!(response.join().unwrap().ends_with("caught"));
}

#[test]
fn inspect_brk_waits_for_the_debugger_and_stops_at_the_first_statement() {
    let (port, debugger) = (18_124, 19_231);
    let _server = inspected(port, debugger, "--inspect-brk");
    let mut dap = Dap::start(debugger);
    dap.request("configurationDone", serde_json::json!({}));
    let stop = dap.stopped();
    assert_eq!((stop.function.as_str(), stop.line, &stop.event["reason"]), ("{main}", 12, &serde_json::json!("entry")));
    dap.command("continue");
    assert!(get(port, "/").ends_with("Hello world slept"));
}

#[test]
fn inspect_keeps_the_session_and_breakpoints_across_watch_reloads() {
    let (port, debugger) = (18_125, 19_232);
    let directory = scratch("inspect-watch");
    let app = directory.join("app.php");
    let script = |version: &str| format!("<?php\nTenma::serve({port}, function ($request) {{\n    $version = '{version}';\n    return \"$version\\n\";\n}});\n");
    std::fs::write(&app, script("one")).unwrap();
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).args(["--watch", &format!("--inspect={debugger}")]).arg(&app).spawn().unwrap());
    let mut dap = Dap::start(debugger);
    dap.break_at(&app, &[4]);
    dap.request("configurationDone", serde_json::json!({}));
    let response = std::thread::spawn(move || get(port, "/"));
    assert_eq!(dap.stopped().local("$version").unwrap().0, "'one'");
    dap.command("continue");
    assert!(response.join().unwrap().ends_with("one\n"));
    std::thread::sleep(Duration::from_millis(200));

    std::fs::write(&app, script("two")).unwrap();
    let started = Instant::now();
    loop {
        let response = std::thread::spawn(move || get(port, "/"));
        let version = dap.stopped().local("$version").unwrap().0;
        dap.command("continue");
        response.join().unwrap();
        if version == "'two'" {
            break;
        }
        assert!(started.elapsed() < Duration::from_secs(5), "no reload");
        std::thread::sleep(Duration::from_millis(50));
    }
    let _ = std::fs::remove_dir_all(directory);
}

#[test]
fn inspect_evaluates_logs_and_changes_variables() {
    let (port, debugger) = (18_126, 19_233);
    let _server = inspected(port, debugger, "--inspect");
    let mut dap = Dap::start(debugger);
    let path = std::fs::canonicalize("tests/fixtures/inspect.php").unwrap();
    let breakpoints = serde_json::json!([
        {"line": 28, "condition": "$id === 1"},
        {"line": 29, "logMessage": "id {$id}, x {$point->x}, {nope(}"},
        {"line": 31, "condition": "$message === 'Hello world'"},
    ]);
    dap.request("setBreakpoints", serde_json::json!({"source": {"path": path}, "breakpoints": breakpoints}));
    dap.request("configurationDone", serde_json::json!({}));

    let response = std::thread::spawn(move || get(port, "/"));
    // The false condition does not stop, the logpoint prints instead of stopping
    let logged = dap.expect("event", "output")["body"].clone();
    assert_eq!(logged["output"].as_str().unwrap().split(", <").next(), Some("id 42, x 7"));
    let stop = dap.stopped();
    assert_eq!(stop.line, 31);
    let frame = dap.request("stackTrace", serde_json::json!({"threadId": stop.event["threadId"]}))["stackFrames"][0]["id"].clone();

    let evaluate = |dap: &mut Dap, expression: &str, context: &str| {
        dap.send("evaluate", serde_json::json!({"expression": expression, "frameId": frame, "context": context}));
        dap.expect("response", "evaluate")
    };
    assert_eq!(evaluate(&mut dap, "$id + 1", "watch")["body"]["result"], "43");
    assert_eq!(evaluate(&mut dap, "strtoupper($message);", "hover")["body"]["result"], "'HELLO WORLD'");
    let point = evaluate(&mut dap, "$point", "watch")["body"].clone();
    assert_eq!(dap.variables(point["variablesReference"].as_u64().unwrap())[0], ("x".to_owned(), "7".to_owned(), 0));
    // Errors come back as failed responses; an exception or a fiber switch does not stop anything
    assert!(evaluate(&mut dap, "nope(", "watch")["message"].as_str().unwrap().starts_with("ParseError"));
    assert!(evaluate(&mut dap, "throw new LogicException('no')", "watch")["message"].as_str().unwrap().starts_with("LogicException: no"));
    assert!(evaluate(&mut dap, "Tenma::sleep(0.01)", "watch")["message"].as_str().unwrap().starts_with("FiberError"));
    // The debug console runs statements too
    assert_eq!(evaluate(&mut dap, "echo 'hi'; $id = 5", "repl")["success"], true);
    assert_eq!(dap.expect("event", "output")["body"]["output"], "hi");
    assert_eq!(evaluate(&mut dap, "$id", "watch")["body"]["result"], "5");
    // A reference handed out earlier is looked up again: after the variable changed it shows nothing
    let point = dap.stopped_locals(&frame);
    let (_, reference) = point;
    assert_eq!(evaluate(&mut dap, "$point = 5", "repl")["success"], true);
    assert!(dap.variables(reference).is_empty());
    assert_eq!(evaluate(&mut dap, "$point = new Point(7)", "repl")["success"], true);
    // Null bytes in keys (mangled properties of a cast object) do not shift the fields
    let cast = evaluate(&mut dap, "(array) new Point(7)", "watch")["body"]["variablesReference"].as_u64().unwrap();
    let fields: Vec<_> = dap.variables(cast).into_iter().map(|(name, value, _)| (name, value)).collect();
    assert_eq!(fields, [("x".to_owned(), "7".to_owned()), ("\\0Point\\0tags".to_owned(), "array(1)".to_owned())]);

    let locals = dap.request("scopes", serde_json::json!({"frameId": frame}))["scopes"][0]["variablesReference"].clone();
    let set = dap.request("setVariable", serde_json::json!({"variablesReference": locals, "name": "$message", "value": "'changed'"}));
    assert_eq!(set["value"], "'changed'");
    dap.command("continue");
    let body = response.join().unwrap();
    assert!(body.ends_with("changed slept"), "{body}");
}

#[test]
fn inspect_moves_breakpoints_to_the_next_statement() {
    let (port, debugger) = (18_127, 19_234);
    let _server = inspected(port, debugger, "--inspect");
    // The script has compiled: the breakpoints are placed right away, not later
    get(port, "/");
    let mut dap = Dap::start(debugger);
    let path = std::fs::canonicalize("tests/fixtures/inspect.php").unwrap();
    // A function's head, its closing brace (PHP's implicit return), a line between two statements,
    // the start of a return over several lines, after the last line
    let breakpoints = serde_json::json!([{"line": 3}, {"line": 6}, {"line": 26}, {"line": 36}, {"line": 80}]);
    let set = dap.request("setBreakpoints", serde_json::json!({"source": {"path": path}, "breakpoints": breakpoints}));
    let placed: Vec<(bool, u64)> = set["breakpoints"].as_array().unwrap().iter().map(|breakpoint| (breakpoint["verified"].as_bool().unwrap(), breakpoint["line"].as_u64().unwrap())).collect();
    assert_eq!(placed[..3], [(true, 4), (true, 6), (true, 27)]);
    assert!(placed[3].0 && (36..=37).contains(&placed[3].1), "{placed:?}");
    assert_eq!(placed[4], (false, 80));
    dap.request("configurationDone", serde_json::json!({}));

    let response = std::thread::spawn(move || get(port, "/"));
    assert_eq!(dap.stopped().line, 27);
    dap.command("continue");
    assert_eq!(dap.stopped().function, "greet");
    // The closing brace stops as greet() returns, after its return statement, with the value
    dap.command("continue");
    let stop = dap.stopped();
    assert_eq!((stop.function.as_str(), stop.line), ("greet", 6));
    assert_eq!(stop.local("return value").unwrap().0, "'Hello world'");
    assert_eq!(stop.local("$text").unwrap().0, "'Hello world'");
    // A step from a return goes on in the caller
    dap.command("next");
    assert_eq!(dap.stopped().line, 30);
    dap.command("continue");
    assert!(response.join().unwrap().ends_with("Hello world slept"));
}

#[test]
fn inspect_hit_counts_exception_classes_debugger_and_setting_children() {
    let (port, debugger) = (18_128, 19_235);
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).args([&format!("--inspect={debugger}"), "tests/fixtures/inspect_more.php"]).env("PORT", port.to_string()).spawn().unwrap());
    let mut dap = Dap::start(debugger);
    let path = std::fs::canonicalize("tests/fixtures/inspect_more.php").unwrap();
    // Every third pass, from the third on: $i is 3, 6, 9
    let breakpoints = serde_json::json!([{"line": 13, "hitCondition": "% 3"}]);
    dap.request("setBreakpoints", serde_json::json!({"source": {"path": path}, "breakpoints": breakpoints}));
    // Only NotFound and its subclasses, though all exceptions are on
    dap.request("setExceptionBreakpoints", serde_json::json!({"filters": [], "filterOptions": [{"filterId": "all", "condition": "\\NotFound"}]}));
    dap.request("configurationDone", serde_json::json!({}));

    let response = std::thread::spawn(move || get(port, "/loop"));
    for expected in ["3", "6", "9"] {
        assert_eq!(dap.stopped().local("$i").unwrap().0, expected);
        dap.command("continue");
    }
    assert!(response.join().unwrap().ends_with("55"));

    let response = std::thread::spawn(move || get(port, "/throw"));
    let stop = dap.stopped();
    assert_eq!((stop.line, &stop.event["description"]), (22, &serde_json::json!("NotFound: missing")));
    dap.command("continue");
    assert!(response.join().unwrap().starts_with("HTTP/1.1 500"));

    // Tenma::debugger() stops in the caller's line; elements and properties can be changed
    let response = std::thread::spawn(move || get(port, "/"));
    let stop = dap.stopped();
    assert_eq!((stop.line, &stop.event["description"]), (27, &serde_json::json!("Tenma::debugger()")));
    let list = dap.variables(stop.local("$list").unwrap().1);
    let set = |dap: &mut Dap, reference: u64, name: &str, value: &str| {
        dap.send("setVariable", serde_json::json!({"variablesReference": reference, "name": name, "value": value}));
        dap.expect("response", "setVariable")
    };
    // $shared shares the array: it keeps its value
    assert_eq!(set(&mut dap, list[0].2, "y", "2")["body"]["value"], "2");
    let item = stop.local("$item").unwrap().1;
    assert_eq!(set(&mut dap, item, "count", "5")["body"]["value"], "5");
    assert_eq!(set(&mut dap, item, "tags", "['b' => 2]")["body"]["value"], "array(1)");
    assert!(set(&mut dap, item, "count", "'many'")["message"].as_str().unwrap().starts_with("TypeError"));
    assert!(set(&mut dap, item, "id", "'y'")["message"].as_str().unwrap().starts_with("Error: Cannot modify readonly property"));
    dap.command("continue");
    let body = response.join().unwrap();
    assert!(body.ends_with(r#"[{"x":{"y":2}},{"x":{"y":1}},5,{"b":2}]"#), "{body}");

    // Without an editor Tenma::debugger() does nothing
    dap.request("disconnect", serde_json::json!({}));
    assert!(get(port, "/").ends_with(r#"[{"x":{"y":1}},{"x":{"y":1}},1,{"a":1}]"#));
}

#[test]
fn review_fixes() {
    use tokio_tungstenite::tungstenite::{Message, connect};

    let port = 18_129;
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/review.php").env("PORT", port.to_string()).spawn().unwrap());
    let body = |response: String| response.rsplit("\r\n\r\n").next().unwrap().to_owned();

    // "//host/admin" stays the path it is; repeated headers join
    let raw = "GET //x/admin?a=1 HTTP/1.1\r\nHost: localhost\r\nCookie: a=1\r\nCookie: b=2\r\nConnection: close\r\n\r\n";
    assert_eq!(body(request(port, raw)), r#"{"path":"\/\/x\/admin","query":{"a":"1"},"cookie":"a=1; b=2"}"#);

    assert!(get(port, "/detach-exit").starts_with("HTTP/1.1 "));
    assert!(get(port, "/").starts_with("HTTP/1.1 200"), "the server lives on");

    // A lone \r breaks lines in EventSource: no forged fields
    let events = get(port, "/events");
    for part in ["data: a\ndata: b\ndata: c\n\n", ": x\n: y\n\n", "data: rejected\n\n"] {
        assert!(events.contains(part), "{events:?}");
    }

    let (mut socket, _) = connect(format!("ws://127.0.0.1:{port}/ws")).unwrap();
    assert_eq!(socket.read().unwrap(), Message::text("ready"));
    socket.send(Message::text("hi")).unwrap();
    assert_eq!(socket.read().unwrap(), Message::text("got hi"));

    // Classic: the media type ignores case; filter_input() never sees the previous request's fields
    let classic = port + 1;
    let form = "--b\r\nContent-Disposition: form-data; name=\"secret\"\r\n\r\nhunter2\r\n--b--\r\n";
    let raw = format!("POST / HTTP/1.1\r\nHost: localhost\r\nContent-Type: Multipart/Form-Data; boundary=b\r\nContent-Length: {}\r\nConnection: close\r\n\r\n{form}", form.len());
    assert!(body(request(classic, &raw)).starts_with(r#"{"post":{"secret":"hunter2"},"filter":"hunter2""#));
    assert_eq!(body(get(classic, "/")), r#"{"post":[],"filter":null,"gc":false}"#);
    assert_eq!(body(get(port, "/all-cancelled")), "Tenma\\Cancelled");

    // exit() does not leave the cycle collector switched off
    get(classic, "/?exit=1");
    assert_eq!(body(get(classic, "/")), r#"{"post":[],"filter":null,"gc":false}"#);
}

#[test]
fn a_second_sigterm_ends_at_once() {
    let port = 18_131;
    let mut server = Command::new(env!("CARGO_BIN_EXE_tenma")).arg("tests/fixtures/review.php").env("PORT", port.to_string()).spawn().unwrap();
    get(port, "/");
    // An endless stream would hold the drain for 10 s
    let mut stream = TcpStream::connect(("127.0.0.1", port)).unwrap();
    write!(stream, "GET /forever HTTP/1.1\r\nHost: localhost\r\n\r\n").unwrap();
    stream.read_exact(&mut [0; 64]).unwrap();
    let started = Instant::now();
    for _ in 0..2 {
        Command::new("kill").args(["-TERM", &server.id().to_string()]).status().unwrap();
        std::thread::sleep(Duration::from_millis(100));
    }
    server.wait().unwrap();
    assert!(started.elapsed() < Duration::from_secs(2), "{:?}", started.elapsed());
}

#[test]
fn publish_reaches_every_worker() {
    let port = 18_132;
    let _server = Server(Command::new(env!("CARGO_BIN_EXE_tenma")).args(["--workers", "3", "tests/fixtures/pubsub.php"]).env("PORT", port.to_string()).spawn().unwrap());

    assert!(get(port, "/publish?message=a").ends_with("published\n"));
    // A subscription made in a request waits there for the next message, from another worker
    let waiting = std::thread::spawn(move || get(port, "/wait"));
    std::thread::sleep(Duration::from_millis(200));
    assert!(get(port, "/publish?message=b").ends_with("published\n"));
    let waited = waiting.join().unwrap();
    assert!(waited.ends_with("waited for b\n"), "{waited}");

    // Round robin: all three workers heard both messages, the cancelled subscription nothing
    for _ in 0..3 {
        let heard = get(port, "/");
        assert!(heard.ends_with("\r\n\r\na,b\n"), "{heard}");
    }
}

#[test]
fn publish_through_redis_reaches_other_processes() {
    if TcpStream::connect("127.0.0.1:6379").is_err() {
        eprintln!("no Redis reachable, test skipped");
        return;
    }
    let ports = [18_133, 18_134];
    let _servers = ports.map(|port| {
        Server(Command::new(env!("CARGO_BIN_EXE_tenma")).args(["--workers", "2", "--pubsub", "redis://127.0.0.1:6379", "tests/fixtures/pubsub.php"]).env("PORT", port.to_string()).spawn().unwrap())
    });
    for port in ports {
        assert!(get(port, "/").starts_with("HTTP/1.1 200"));
    }
    // The bridges subscribe on their own thread once the workers listen
    std::thread::sleep(Duration::from_millis(300));

    assert!(get(ports[0], "/publish?message=a").ends_with("published\n"));
    std::thread::sleep(Duration::from_millis(100));
    for port in ports {
        for _ in 0..2 {
            let heard = get(port, "/");
            assert!(heard.ends_with("\r\n\r\na\n"), "{heard}");
        }
    }
}
