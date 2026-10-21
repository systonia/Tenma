//! `tenma replay FILE`: plays a journal (src/journal.rs) into a fresh interpreter. One worker runs the
//! recorded script without ports; every request is injected when its turn comes, every op PHP starts
//! is answered from the journal instead of running, and the wrapped PHP functions (time(),
//! random_int()) return what they returned then. If PHP asks for something else than the journal has
//! next, the code is not the recorded code: the replay stops and shows both sides.
//!
//! `--check` compares every response with the recorded one, `--until N` stops after request N. With
//! `--inspect` the debugger can also step back: src/inspect.rs restarts the worker, which replays
//! again up to the statement before.

use std::cell::RefCell;
use std::collections::HashMap;
use std::rc::Rc;
use std::time::Duration;

use tokio::sync::Notify;

use crate::journal::{self, Entry, RawRequest, Reply};
use crate::php;

/// What `tenma replay` was asked to do, shared with the worker.
pub struct Plan {
    pub entries: Vec<Entry>,
    pub until: Option<u64>,
    pub check: bool,
}

struct State {
    entries: Rc<Vec<Entry>>,
    at: usize,
    /// Ops PHP has started and waits for: what they are, by ID.
    pending: HashMap<u64, (String, String)>,
    started: Rc<Notify>,
    diverged: Option<String>,
    check: bool,
    until: Option<u64>,
    /// Recorded and actual responses by request, until both are there and compared.
    expected: HashMap<u64, Reply>,
    actual: HashMap<u64, Reply>,
    responses: u64,
    mismatches: u64,
    warned: std::collections::HashSet<String>,
    /// Streamed bodies and WebSocket pipes PHP writes into: kept so the writes find a reader.
    keep: Vec<php::Reply>,
}

thread_local! {
    static STATE: RefCell<Option<State>> = const { RefCell::new(None) };
}

fn with<T>(f: impl FnOnce(&mut State) -> T) -> Option<T> {
    STATE.with_borrow_mut(|state| state.as_mut().map(f))
}

/// Before the script loads: the journal this worker plays.
pub fn prepare(plan: &Plan) {
    STATE.with_borrow_mut(|state| {
        *state = Some(State {
            entries: Rc::new(plan.entries.clone()),
            // After the header, which replay_journal has read already
            at: 1,
            pending: HashMap::new(),
            started: Rc::new(Notify::new()),
            diverged: None,
            check: plan.check,
            until: plan.until,
            expected: HashMap::new(),
            actual: HashMap::new(),
            responses: 0,
            mismatches: 0,
            warned: std::collections::HashSet::new(),
            keep: Vec::new(),
        });
    });
    journal::set_mode(2);
}

/// The next entry, without taking it.
fn peek(state: &State) -> Option<&Entry> {
    state.entries.get(state.at)
}

/// An op PHP starts: answered from the journal later, so no task runs; its ID as recorded.
pub fn intercept(kind: &str, detail: &str) -> Option<u64> {
    with(|state| {
        let op = php::next_id();
        state
            .pending
            .insert(op, (kind.to_owned(), detail.to_owned()));
        state.started.notify_one();
        op
    })
}

/// The recorded value of a wrapped function, if it is what the journal has next.
pub fn take_value(name: &str) -> Option<Vec<u8>> {
    with(|state| {
        if state.diverged.is_some() {
            return None;
        }
        // Notes stand between the entries and say nothing about the order
        while let Some(Entry::Note(text)) = peek(state) {
            eprintln!("tenma replay: note: {text}");
            state.at += 1;
        }
        match peek(state) {
            Some(Entry::Value {
                name: recorded,
                value,
            }) if recorded == name => {
                let value = value.clone();
                state.at += 1;
                Some(value)
            }
            Some(other) => {
                state.diverged = Some(format!(
                    "PHP called {name}() but the journal has {} next",
                    describe(other)
                ));
                None
            }
            None => {
                state.diverged = Some(format!("PHP called {name}() after the journal's end"));
                None
            }
        }
    })
    .flatten()
}

/// A blocking function ran during the replay: once per name, a warning.
pub fn blocking(name: &str) {
    with(|state| {
        if state.warned.insert(name.to_owned()) {
            eprintln!("tenma replay: {name}() blocks and is not replayed, its effects may differ");
        }
    });
}

/// One entry in a few words, for messages.
fn describe(entry: &Entry) -> String {
    match entry {
        Entry::Header(_) => "the header".into(),
        Entry::Started => "the start of the event loop".into(),
        Entry::Request { rid, request } => format!(
            "request {rid} ({} {})",
            String::from_utf8_lossy(&request.method),
            String::from_utf8_lossy(&request.uri)
        ),
        Entry::Response { rid, .. } => format!("the response of request {rid}"),
        Entry::Op {
            op, kind, detail, ..
        } => format!("op {op} ({kind} {detail})"),
        Entry::Gone { rid } => format!("the client of request {rid} leaving"),
        Entry::Value { name, .. } => format!("a value of {name}()"),
        Entry::Files(_) => "the list of files".into(),
        Entry::Note(text) => format!("a note: {text}"),
    }
}

/// How long PHP may take to start the op the journal expects next; it starts ops synchronously, so
/// this only runs out when the code no longer starts it.
const START_WAIT: Duration = Duration::from_secs(2);

/// Plays the journal from after the script ran until its end, a divergence or --until. The exit
/// status: 0 when everything matched, 1 otherwise.
pub async fn drive() -> u8 {
    let entries = with(|state| Rc::clone(&state.entries)).unwrap_or_default();
    let started = with(|state| Rc::clone(&state.started)).unwrap_or_default();
    loop {
        if let Some(message) = with(|state| state.diverged.clone()).flatten() {
            eprintln!("tenma replay: diverged: {message}");
            return 1;
        }
        let Some(at) = with(|state| state.at) else {
            return 1;
        };
        let Some(entry) = entries.get(at) else {
            break;
        };
        match entry {
            Entry::Header(_) | Entry::Note(_) => {
                if let Entry::Note(text) = entry {
                    eprintln!("tenma replay: note: {text}");
                }
                with(|state| state.at += 1);
            }
            Entry::Started => {
                with(|state| state.at += 1);
                if php::started().is_err() {
                    eprintln!("tenma replay: fatal error in the script's startup work");
                    return 1;
                }
            }
            Entry::Request { rid, request } => {
                with(|state| state.at += 1);
                php::set_next_id(*rid);
                inject(*rid, request);
            }
            Entry::Response { rid, reply } => {
                with(|state| {
                    state.at += 1;
                    state.expected.insert(*rid, reply.clone());
                });
                compare(*rid);
                let done = with(|state| state.until.is_some_and(|until| state.responses >= until))
                    .unwrap_or(true);
                if done {
                    eprintln!(
                        "tenma replay: stopped after request {}",
                        with(|state| state.responses).unwrap_or(0)
                    );
                    break;
                }
            }
            Entry::Op {
                op,
                kind,
                detail,
                result,
            } => {
                // PHP starts an op synchronously while it runs; wait until it has started this one
                let waited = tokio::time::timeout(START_WAIT, async {
                    loop {
                        let found = with(|state| state.pending.remove(op)).flatten();
                        if let Some(found) = found {
                            return Some(found);
                        }
                        started.notified().await;
                    }
                })
                .await;
                let Ok(Some((started_kind, started_detail))) = waited else {
                    let others = with(|state| {
                        state
                            .pending
                            .values()
                            .map(|(kind, detail)| format!("{kind} {detail}"))
                            .collect::<Vec<_>>()
                    })
                    .unwrap_or_default();
                    eprintln!(
                        "tenma replay: diverged: the journal has op {op} ({kind} {detail}) next, but PHP {}",
                        if others.is_empty() {
                            "started no op".to_owned()
                        } else {
                            format!("started {}", others.join(", "))
                        }
                    );
                    return 1;
                };
                if started_kind != *kind || started_detail != *detail {
                    eprintln!(
                        "tenma replay: diverged: the journal has op {op} ({kind} {detail}), but PHP started {started_kind} {started_detail}"
                    );
                    return 1;
                }
                with(|state| state.at += 1);
                if php::complete(*op, result.clone()).is_err() {
                    eprintln!("tenma replay: fatal error while resuming op {op}");
                    return 1;
                }
            }
            Entry::Gone { rid } => {
                with(|state| state.at += 1);
                php::gone(*rid);
            }
            Entry::Value { name, .. } => {
                eprintln!(
                    "tenma replay: diverged: the journal has a value of {name}() next, but PHP did not call it"
                );
                return 1;
            }
            Entry::Files(files) => {
                with(|state| state.at += 1);
                let changed: Vec<&str> = files
                    .iter()
                    .filter(|(path, hash)| !hash.is_empty() && journal::hash_file(path) != *hash)
                    .map(|(path, _)| path.as_str())
                    .collect();
                if !changed.is_empty() {
                    eprintln!(
                        "tenma replay: {} file(s) differ from the recording: {}",
                        changed.len(),
                        changed.join(", ")
                    );
                }
            }
        }
        // Let the response tasks run between steps
        tokio::task::yield_now().await;
    }
    tokio::task::yield_now().await;
    let (responses, mismatches, check) =
        with(|state| (state.responses, state.mismatches, state.check)).unwrap_or((0, 0, false));
    eprintln!(
        "tenma replay: {responses} request(s) replayed{}",
        if check {
            format!(", {mismatches} response(s) differ")
        } else {
            String::new()
        }
    );
    u8::from(mismatches > 0)
}

/// Hands a recorded request to PHP and keeps whatever it answers.
fn inject(rid: u64, request: &RawRequest) {
    let (remote, local) = (
        request.remote.parse().unwrap_or(([127, 0, 0, 1], 0).into()),
        request.local.parse().unwrap_or(([127, 0, 0, 1], 0).into()),
    );
    let outcome = php::handle(
        request.handler,
        &php::Request {
            method: &request.method,
            uri: &request.uri,
            headers: request
                .headers
                .iter()
                .map(|(name, value)| (name.as_slice(), value.as_slice()))
                .collect(),
            body: &request.body,
            remote,
            local,
            https: request.https,
            protocol: &request.protocol,
        },
    );
    let failed = || Reply::Full {
        status: 500,
        headers: Vec::new(),
        body: b"Internal Server Error\n".to_vec(),
    };
    match outcome {
        php::Outcome::Pending(pending) => {
            tokio::task::spawn_local(async move {
                let reply = match pending.await {
                    Ok(reply) => reply,
                    Err(_) => {
                        answered(rid, failed());
                        return;
                    }
                };
                let recorded = match &reply {
                    php::Reply::Full(response) => Reply::Full {
                        status: response.status,
                        headers: response.headers.clone(),
                        body: response.body.clone(),
                    },
                    php::Reply::Stream {
                        status, headers, ..
                    } => Reply::Stream {
                        status: *status,
                        headers: headers.clone(),
                    },
                    php::Reply::Upgrade(_) => Reply::Upgrade,
                };
                // The channel body or the pipes stay alive, or PHP's writes would fail
                with(|state| state.keep.push(reply));
                answered(rid, recorded);
            });
        }
        php::Outcome::Failed => answered(rid, failed()),
        php::Outcome::Fatal | php::Outcome::Broken(_) => {
            eprintln!("tenma replay: fatal error in request {rid}");
            with(|state| state.diverged = Some(format!("fatal error in request {rid}")));
        }
    }
}

fn answered(rid: u64, reply: Reply) {
    with(|state| {
        state.responses += 1;
        state.actual.insert(rid, reply);
    });
    compare(rid);
}

/// With --check: once both the recorded and the actual response are there, they are compared.
fn compare(rid: u64) {
    with(|state| {
        if !state.check || !state.expected.contains_key(&rid) || !state.actual.contains_key(&rid) {
            return;
        }
        let expected = state.expected.remove(&rid).unwrap();
        let actual = state.actual.remove(&rid).unwrap();
        if let Some(difference) = differ(&expected, &actual) {
            state.mismatches += 1;
            eprintln!("tenma replay: request {rid} differs: {difference}");
        }
    });
}

/// Where two responses differ, in a sentence; headers that always change (date) do not count.
fn differ(expected: &Reply, actual: &Reply) -> Option<String> {
    fn headers(headers: &[(Vec<u8>, Vec<u8>)]) -> Vec<(String, String)> {
        let mut list: Vec<(String, String)> = headers
            .iter()
            .map(|(name, value)| {
                (
                    String::from_utf8_lossy(name).to_ascii_lowercase(),
                    String::from_utf8_lossy(value).into_owned(),
                )
            })
            .filter(|(name, _)| name != "date")
            .collect();
        list.sort();
        list
    }
    match (expected, actual) {
        (
            Reply::Full {
                status,
                headers: expected_headers,
                body,
            },
            Reply::Full {
                status: actual_status,
                headers: actual_headers,
                body: actual_body,
            },
        ) => {
            if status != actual_status {
                return Some(format!("status {status} recorded, {actual_status} now"));
            }
            if headers(expected_headers) != headers(actual_headers) {
                return Some("the headers differ".into());
            }
            if body != actual_body {
                return Some(format!(
                    "the body differs: {} byte(s) recorded, {} now",
                    body.len(),
                    actual_body.len()
                ));
            }
            None
        }
        (Reply::Stream { status, .. }, Reply::Stream { status: now, .. }) if status == now => None,
        (Reply::Upgrade, Reply::Upgrade) => None,
        (expected, actual) => Some(format!("{} recorded, {} now", kind(expected), kind(actual))),
    }
}

fn kind(reply: &Reply) -> String {
    match reply {
        Reply::Full { status, .. } => format!("a response with status {status}"),
        Reply::Stream { status, .. } => format!("a stream with status {status}"),
        Reply::Upgrade => "a WebSocket".into(),
    }
}

/// Reads and checks the journal file and the options after it: `--until N`, `--check`.
pub fn plan(args: &[String]) -> Result<Plan, String> {
    let Some(file) = args.first() else {
        return Err("tenma replay needs the journal file".into());
    };
    let data = std::fs::read(file).map_err(|error| format!("{file}: {error}"))?;
    let entries = journal::read_all(&data);
    if !matches!(entries.first(), Some(Entry::Header(_))) {
        return Err(format!("{file}: not a tenma journal"));
    }
    let mut plan = Plan {
        entries,
        until: None,
        check: false,
    };
    let mut rest = args[1..].iter();
    while let Some(arg) = rest.next() {
        match arg.as_str() {
            "--check" => plan.check = true,
            "--until" => {
                let number = rest.next().ok_or("--until needs a request number")?;
                plan.until = Some(
                    number
                        .parse()
                        .ok()
                        .filter(|&n| n > 0)
                        .ok_or(format!("--until {number}: not a number above 0"))?,
                );
            }
            other => return Err(format!("tenma replay: unknown option {other}")),
        }
    }
    Ok(plan)
}

/// What the header says the recorded process ran: the script's argv, its environment and directory.
pub fn recorded_run(plan: &Plan) -> Result<(Vec<String>, HashMap<String, String>, String), String> {
    let Some(Entry::Header(json)) = plan.entries.first() else {
        return Err("no header".into());
    };
    let header: serde_json::Value =
        serde_json::from_str(json).map_err(|error| format!("header: {error}"))?;
    let mut argv: Vec<String> = header["argv"]
        .as_array()
        .map(|list| {
            list.iter()
                .filter_map(|arg| arg.as_str().map(str::to_owned))
                .collect()
        })
        .unwrap_or_default();
    if let Some(script) = header["script"].as_str()
        && !argv.is_empty()
    {
        argv[0] = script.to_owned();
    }
    if argv.is_empty() {
        return Err("header: no script".into());
    }
    let env = header["env"]
        .as_object()
        .map(|map| {
            map.iter()
                .filter_map(|(key, value)| {
                    value.as_str().map(|value| (key.clone(), value.to_owned()))
                })
                .collect()
        })
        .unwrap_or_default();
    let cwd = header["cwd"].as_str().unwrap_or_default().to_owned();
    if let (Some(tenma), Some(php)) = (header["tenma"].as_str(), header["php"].as_str())
        && (tenma != env!("CARGO_PKG_VERSION") || php != env!("TENMA_PHP_VERSION"))
    {
        eprintln!(
            "tenma replay: recorded with tenma {tenma} and PHP {php}, this is tenma {} and PHP {}",
            env!("CARGO_PKG_VERSION"),
            env!("TENMA_PHP_VERSION")
        );
    }
    Ok((argv, env, cwd))
}
