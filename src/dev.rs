//! The hooks of tenma.dev.php (`tenma --watch`, `tenma build`). The stdlib keeps the hook list,
//! its order and the PHP closures (`Tenma\Internal\dev`); Rust watches the hooks' directories,
//! delivers the changes and runs command hooks as processes of their own.

use std::os::unix::ffi::OsStrExt;
use std::path::{Path, PathBuf};
use std::sync::{OnceLock, mpsc};
use std::time::Duration;

use tokio::io::{AsyncBufReadExt, AsyncRead, BufReader};

use crate::ops;
use crate::php::{OpResult, bytes};

/// The name of the dev script, next to the app script.
pub const FILE: &str = "tenma.dev.php";

/// Signals once the hooks' first run is over (or the dev script failed): the workers start after
/// it, so the first request already finds the hooks' output.
static SETTLED: OnceLock<mpsc::Sender<()>> = OnceLock::new();

/// The tenma.dev.php next to the script, if there is one.
pub fn find(script: &str) -> Option<PathBuf> {
    let directory = Path::new(script).parent()?;
    let directory = if directory.as_os_str().is_empty() { Path::new(".") } else { directory };
    directory.join(FILE).canonicalize().ok()
}

/// PHP code that runs the dev script: `watch` keeps it running and waiting for changes.
pub fn code(path: &Path, watch: bool) -> String {
    let quoted = String::from_utf8_lossy(path.as_os_str().as_bytes()).replace('\\', "\\\\").replace('\'', "\\'");
    format!("\\Tenma\\Internal\\dev('{quoted}', {watch});")
}

/// A receiver that hears once the first run of the hooks is over.
pub fn settled_receiver() -> mpsc::Receiver<()> {
    let (sender, receiver) = mpsc::channel();
    let _ = SETTLED.set(sender);
    receiver
}

pub fn settled() {
    if let Some(sender) = SETTLED.get() {
        let _ = sender.send(());
    }
}

struct Command {
    name: String,
    command: Vec<String>,
    directory: PathBuf,
}

fn string(value: &serde_json::Value) -> String {
    value.as_str().unwrap_or_default().to_owned()
}

/// exit() in a hook with --watch: tenma ends with EXIT_STATUS. Apart from php::EXIT, which the
/// workers wait on for exit() in the script.
pub static EXIT: std::sync::LazyLock<tokio::sync::Notify> = std::sync::LazyLock::new(tokio::sync::Notify::new);
static EXIT_STATUS: std::sync::atomic::AtomicI32 = std::sync::atomic::AtomicI32::new(-1);

pub fn request_exit(status: u8) {
    EXIT_STATUS.store(i32::from(status), std::sync::atomic::Ordering::Relaxed);
    EXIT.notify_one();
}

/// The status of exit() in a hook, if it ran.
pub fn exit_status() -> Option<u8> {
    u8::try_from(EXIT_STATUS.load(std::sync::atomic::Ordering::Relaxed)).ok()
}

/// A command hook's process group gets SIGTERM, whatever still runs after this SIGKILL.
const KILL_AFTER: Duration = Duration::from_secs(2);
/// A process the hook left in the background may keep its pipes open: no longer waited for.
const OUTPUT_AFTER_EXIT: Duration = Duration::from_millis(100);

#[unsafe(no_mangle)]
extern "C" fn tenma_rs_dev(op: *const u8, op_len: usize, arg: *const u8, arg_len: usize) -> u64 {
    // Hooks load classes as they run (autoloader): those files reload the hooks too
    crate::watch::report();
    let arg = bytes(arg, arg_len).to_vec();
    match bytes(op, op_len) {
        // {"roots": [[path, recursive], …], "directory": …}
        b"watch" => {
            let value: serde_json::Value = serde_json::from_slice(&arg).unwrap_or_default();
            let roots: Vec<(PathBuf, bool)> = serde_json::from_value(value["roots"].clone()).unwrap_or_default();
            let directory = PathBuf::from(string(&value["directory"]));
            ops::spawn(async move {
                crate::watch::roots(roots, directory).await;
                OpResult::Null
            })
        }
        b"settled" => {
            settled();
            ops::spawn(async { OpResult::Null })
        }
        // Changed paths below the hooks' directories and their modification times, all separated
        // by null bytes
        b"next" => ops::spawn(async {
            match crate::watch::hook_changes().await {
                Some(paths) => {
                    let mut fields = Vec::new();
                    for (path, modified) in paths {
                        fields.extend_from_slice(path.as_os_str().as_bytes());
                        fields.push(0);
                        fields.extend_from_slice(modified.to_string().as_bytes());
                        fields.push(0);
                    }
                    OpResult::Text(fields)
                }
                None => std::future::pending().await,
            }
        }),
        // {"name": …, "command": [program, argument, …], "directory": …}
        b"run" => {
            let value: serde_json::Value = serde_json::from_slice(&arg).unwrap_or_default();
            let command = Command {
                name: string(&value["name"]),
                command: value["command"].as_array().map(|parts| parts.iter().map(string).collect()).unwrap_or_default(),
                directory: string(&value["directory"]).into(),
            };
            ops::spawn(run(command))
        }
        _ => 0,
    }
}

/// Ends a command hook's processes once the hook is over or dropped (Ctrl+C, reload), also those
/// it started itself (`sh -c`, background jobs): SIGTERM to its process group, SIGKILL after
/// KILL_AFTER to whatever is left.
struct Group(i32);

impl Drop for Group {
    fn drop(&mut self) {
        let group = -self.0;
        // Fails once nothing of the group is left, the usual case
        if unsafe { libc::kill(group, libc::SIGTERM) } == 0 {
            // ponytail: if tenma exits meanwhile, only SIGTERM was sent
            std::thread::spawn(move || {
                std::thread::sleep(KILL_AFTER);
                unsafe { libc::kill(group, libc::SIGKILL) };
            });
        }
    }
}

/// Runs a command hook without a shell; its output appears with the hook's name in front. The
/// exit status is the result.
async fn run(command: Command) -> OpResult {
    let Some((program, arguments)) = command.command.split_first() else {
        return OpResult::Error("empty command".into());
    };
    // Relative to the project, not to tenma's working directory
    let program = if program.contains('/') { command.directory.join(program) } else { PathBuf::from(program) };
    let child = tokio::process::Command::new(&program)
        .args(arguments)
        .current_dir(&command.directory)
        .stdin(std::process::Stdio::null())
        .stdout(std::process::Stdio::piped())
        .stderr(std::process::Stdio::piped())
        .process_group(0)
        .spawn();
    let mut child = match child {
        Ok(child) => child,
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => return OpResult::Error(format!("{} not found", program.display())),
        Err(error) => return OpResult::Error(format!("{}: {error}", program.display())),
    };
    let _group = child.id().map(|pid| Group(pid as i32));
    let stdout = prefixed(child.stdout.take(), command.name.clone(), false);
    let stderr = prefixed(child.stderr.take(), command.name, true);
    let output = tokio::spawn(async move { tokio::join!(stdout, stderr) });
    let status = child.wait().await;
    let _ = tokio::time::timeout(OUTPUT_AFTER_EXIT, output).await;
    match status {
        Ok(status) => OpResult::Text(status.code().unwrap_or(1).to_string().into_bytes()),
        Err(error) => OpResult::Error(error.to_string()),
    }
}

async fn prefixed(pipe: Option<impl AsyncRead + Unpin>, name: String, error: bool) {
    let Some(pipe) = pipe else { return };
    let mut lines = BufReader::new(pipe).lines();
    while let Ok(Some(line)) = lines.next_line().await {
        if error {
            eprintln!("[{name}] {line}");
        } else {
            println!("[{name}] {line}");
        }
    }
}
