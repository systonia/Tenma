//! `tenma --watch`: reloads the workers when a file the script loaded changes, like `node --watch`.
//! Workers report the files PHP loaded, also later ones (classes the autoloader loads on the first
//! request). A thread watches their directories, not the files: many editors save by writing a
//! new file and renaming it over the old one. Only a different content counts as a change.

use std::cell::Cell;
use std::collections::{HashMap, HashSet};
use std::hash::{Hash, Hasher};
use std::os::unix::ffi::OsStrExt;
use std::path::{Path, PathBuf};
use std::sync::{OnceLock, mpsc};
use std::time::{Duration, Instant};

use notify::Watcher;
use tokio::sync::mpsc::{UnboundedReceiver, UnboundedSender, unbounded_channel};

/// Saving often writes several times, `git checkout` changes many files: one reload for all, once
/// no event came for SETTLE, at the latest after MAX_SETTLE.
const SETTLE: Duration = Duration::from_millis(100);
const MAX_SETTLE: Duration = Duration::from_secs(1);

enum Message {
    Loaded(PathBuf),
    Event(notify::Result<notify::Event>),
}

/// The way to the watcher thread; only set with --watch.
static WATCHER: OnceLock<mpsc::Sender<Message>> = OnceLock::new();

thread_local! {
    /// How many of this interpreter's loaded files are already reported.
    static REPORTED: Cell<usize> = const { Cell::new(0) };
}

unsafe extern "C" {
    fn tenma_set_watch();
    fn tenma_report_included(from: usize) -> usize;
}

#[unsafe(no_mangle)]
extern "C" fn tenma_rs_included(path: *const u8, len: usize) {
    if let Some(watcher) = WATCHER.get() {
        let path = Path::new(std::ffi::OsStr::from_bytes(crate::php::bytes(path, len)));
        let _ = watcher.send(Message::Loaded(path.to_path_buf()));
    }
}

/// Reports the files PHP loaded since the last call. Cheap when nothing is new: compares a count.
pub fn report() {
    if WATCHER.get().is_some() {
        REPORTED.set(unsafe { tenma_report_included(REPORTED.get()) });
    }
}

/// A new interpreter starts with nothing reported.
pub fn forget() {
    REPORTED.set(0);
}

/// Before `php::start`: settings for watch mode (opcache checks timestamps on every load).
pub fn configure() {
    unsafe { tenma_set_watch() }
}

/// Starts the watcher thread. The receiver gets one changed file per reload.
pub fn start() -> Result<UnboundedReceiver<PathBuf>, String> {
    let (sender, messages) = mpsc::channel();
    let events = sender.clone();
    let watcher = notify::recommended_watcher(move |event| {
        let _ = events.send(Message::Event(event));
    })
    .map_err(|error| format!("--watch: {error}"))?;
    let _ = WATCHER.set(sender);
    let (changes, receiver) = unbounded_channel();
    std::thread::Builder::new()
        .name("tenma-watch".into())
        .spawn(move || watch(watcher, &messages, &changes))
        .map_err(|error| format!("--watch: {error}"))?;
    Ok(receiver)
}

fn watch(mut watcher: notify::RecommendedWatcher, messages: &mpsc::Receiver<Message>, changes: &UnboundedSender<PathBuf>) {
    // Loaded files with the fingerprint of their content (None: missing)
    let mut files: HashMap<PathBuf, Option<u64>> = HashMap::new();
    let mut directories = HashSet::new();
    let mut candidates = HashSet::new();
    // Since when events come, and until when to wait for more
    let mut settle: Option<(Instant, Instant)> = None;
    loop {
        let message = match settle {
            Some((_, deadline)) => messages.recv_timeout(deadline.saturating_duration_since(Instant::now())),
            None => messages.recv().map_err(|_| mpsc::RecvTimeoutError::Disconnected),
        };
        match message {
            Ok(Message::Loaded(path)) => {
                if files.contains_key(&path) {
                    continue;
                }
                if let Some(directory) = path.parent()
                    && directories.insert(directory.to_path_buf())
                {
                    let _ = watcher.watch(directory, notify::RecursiveMode::NonRecursive);
                }
                files.insert(path.clone(), fingerprint(&path));
            }
            Ok(Message::Event(Ok(event))) => {
                for path in event.paths {
                    if let Some(path) = known(&files, path) {
                        candidates.insert(path);
                        let now = Instant::now();
                        let since = settle.map_or(now, |(since, _)| since);
                        settle = Some((since, (now + SETTLE).min(since + MAX_SETTLE)));
                    }
                }
            }
            Ok(Message::Event(Err(_))) => {}
            Err(mpsc::RecvTimeoutError::Timeout) => {
                settle = None;
                // Compared only now that the writing has settled: no reload for a half-written file
                let mut changed = None;
                for path in candidates.drain() {
                    let now = fingerprint(&path);
                    if files.insert(path.clone(), now) != Some(now) {
                        changed.get_or_insert(path);
                    }
                }
                if let Some(path) = changed
                    && changes.send(path).is_err()
                {
                    return;
                }
            }
            Err(mpsc::RecvTimeoutError::Disconnected) => return,
        }
    }
}

/// The loaded file an event is about. Events may name it by another path than PHP (a symlinked
/// directory such as /tmp on macOS): then the resolved directory decides.
fn known(files: &HashMap<PathBuf, Option<u64>>, path: PathBuf) -> Option<PathBuf> {
    if files.contains_key(&path) {
        return Some(path);
    }
    let resolved = path.parent()?.canonicalize().ok()?.join(path.file_name()?);
    files.contains_key(&resolved).then_some(resolved)
}

fn fingerprint(path: &Path) -> Option<u64> {
    let content = std::fs::read(path).ok()?;
    let mut hasher = std::hash::DefaultHasher::new();
    content.hash(&mut hasher);
    Some(hasher.finish())
}
