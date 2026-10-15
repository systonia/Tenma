//! `tenma --watch`: reloads the workers when a file the script loaded changes, like `node --watch`.
//! Workers report the files PHP loaded, also later ones (classes the autoloader loads on the first
//! request). A thread watches their directories, not the files: many editors save by writing a
//! new file and renaming it over the old one. Only a different content counts as a change.
//!
//! The dev thread (tenma.dev.php, src/dev.rs) reports its files as well, and the directories its
//! hooks read: changes there go to the hooks, which match them against their globs. `.tenma-ignore`
//! next to tenma.dev.php leaves out what is no input (see Ignore).

use std::cell::Cell;
use std::collections::{HashMap, HashSet};
use std::hash::{Hash, Hasher};
use std::os::unix::ffi::OsStrExt;
use std::path::{Path, PathBuf};
use std::sync::{OnceLock, mpsc};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

use notify::Watcher;
use tokio::sync::mpsc::{UnboundedReceiver, UnboundedSender, unbounded_channel};

/// Saving often writes several times, `git checkout` changes many files: one reload for all, once
/// no event came for SETTLE, at the latest after MAX_SETTLE.
const SETTLE: Duration = Duration::from_millis(100);
const MAX_SETTLE: Duration = Duration::from_secs(1);

/// Who loaded a file: the workers running the script, or the dev thread running tenma.dev.php.
const APP: u8 = 1;
const DEV: u8 = 2;

enum Message {
    /// A file PHP loaded, by whom, and the earliest time it may have been loaded.
    Loaded(PathBuf, u8, SystemTime),
    /// Directories the hooks read, recursively or not, and the project with `.tenma-ignore`.
    Roots(Vec<(PathBuf, bool)>, PathBuf),
    Event(notify::Result<notify::Event>),
}

/// What changed once the writing settled: the first file of the script and of tenma.dev.php.
#[derive(Default)]
pub struct Changes {
    pub app: Option<PathBuf>,
    pub dev: Option<PathBuf>,
}

/// The way to the watcher thread; only set with --watch.
static WATCHER: OnceLock<mpsc::Sender<Message>> = OnceLock::new();
/// Changed files below the hooks' directories with their modification time, for the dev thread.
static HOOKS: OnceLock<tokio::sync::Mutex<UnboundedReceiver<Vec<(PathBuf, f64)>>>> = OnceLock::new();

thread_local! {
    /// How many of this interpreter's loaded files are already reported.
    static REPORTED: Cell<usize> = const { Cell::new(0) };
    /// Since when the files not yet reported may have been loaded: the last report, or the start.
    static SINCE: Cell<SystemTime> = const { Cell::new(UNIX_EPOCH) };
    static GROUP: Cell<u8> = const { Cell::new(APP) };
}

unsafe extern "C" {
    fn tenma_set_watch();
    fn tenma_report_included(from: usize) -> usize;
}

#[unsafe(no_mangle)]
extern "C" fn tenma_rs_included(path: *const u8, len: usize) {
    if let Some(watcher) = WATCHER.get() {
        let path = Path::new(std::ffi::OsStr::from_bytes(crate::php::bytes(path, len)));
        let _ = watcher.send(Message::Loaded(path.to_path_buf(), GROUP.get(), SINCE.get()));
    }
}

/// Reports the files PHP loaded since the last call. Cheap when nothing is new: compares a count.
pub fn report() {
    if WATCHER.get().is_some() {
        let now = SystemTime::now();
        REPORTED.set(unsafe { tenma_report_included(REPORTED.get()) });
        SINCE.set(now);
    }
}

/// A new interpreter starts with nothing reported.
pub fn forget() {
    REPORTED.set(0);
    SINCE.set(SystemTime::now());
}

/// This thread runs tenma.dev.php: its files restart it, not the workers.
pub fn dev_thread() {
    GROUP.set(DEV);
}

/// The dev thread with --watch, as opposed to `tenma build`.
pub fn is_dev_thread() -> bool {
    WATCHER.get().is_some() && GROUP.get() == DEV
}

/// Watches the directories the hooks read. Changes still queued from the previous interpreter are
/// dropped: the hooks run once anyway when they start.
pub async fn roots(roots: Vec<(PathBuf, bool)>, project: PathBuf) {
    let (Some(watcher), Some(hooks)) = (WATCHER.get(), HOOKS.get()) else {
        return;
    };
    let mut hooks = hooks.lock().await;
    while hooks.try_recv().is_ok() {}
    let _ = watcher.send(Message::Roots(roots, project));
}

/// The next changed files below the hooks' directories with their modification time (0: gone).
pub async fn hook_changes() -> Option<Vec<(PathBuf, f64)>> {
    HOOKS.get()?.lock().await.recv().await
}

/// Before `php::start`: settings for watch mode (opcache checks timestamps on every load).
pub fn configure() {
    unsafe { tenma_set_watch() }
}

/// Starts the watcher thread. The receiver gets the changes of each settled batch.
pub fn start() -> Result<UnboundedReceiver<Changes>, String> {
    let (sender, messages) = mpsc::channel();
    let events = sender.clone();
    let watcher = notify::recommended_watcher(move |event| {
        let _ = events.send(Message::Event(event));
    })
    .map_err(|error| format!("--watch: {error}"))?;
    let _ = WATCHER.set(sender);
    let (hooks, hook_receiver) = unbounded_channel();
    let _ = HOOKS.set(tokio::sync::Mutex::new(hook_receiver));
    let (changes, receiver) = unbounded_channel();
    std::thread::Builder::new()
        .name("tenma-watch".into())
        .spawn(move || watch(watcher, &messages, &changes, &hooks))
        .map_err(|error| format!("--watch: {error}"))?;
    Ok(receiver)
}

/// Below a hook root watched recursively: watching the directory on its own again would replace
/// that watch on macOS (FSEvents keeps one mode per path) and lose its subdirectories.
fn covered(directory: &Path, roots: &[(PathBuf, bool)]) -> bool {
    roots.iter().any(|(root, recursive)| *recursive && (directory.starts_with(root) || root.canonicalize().is_ok_and(|root| directory.starts_with(root))))
}

fn watch(mut watcher: notify::RecommendedWatcher, messages: &mpsc::Receiver<Message>, changes: &UnboundedSender<Changes>, hooks: &UnboundedSender<Vec<(PathBuf, f64)>>) {
    // Fingerprints of the content of the files seen so far (None: missing)
    let mut files: HashMap<PathBuf, Option<u64>> = HashMap::new();
    // Loaded files and who loaded them
    let mut loaded: HashMap<PathBuf, u8> = HashMap::new();
    let mut directories = HashSet::new();
    // Directories that could not be watched (inotify's max_user_watches): reported once each
    let mut failed = HashSet::new();
    let mut roots: Vec<(PathBuf, bool)> = Vec::new();
    let mut ignore = Ignore::default();
    let mut candidates = HashSet::new();
    // Since when events come, and until when to wait for more
    let mut settle: Option<(Instant, Instant)> = None;
    loop {
        let message = match settle {
            Some((_, deadline)) => messages.recv_timeout(deadline.saturating_duration_since(Instant::now())),
            None => messages.recv().map_err(|_| mpsc::RecvTimeoutError::Disconnected),
        };
        match message {
            Ok(Message::Loaded(path, group, since)) => {
                // On every report, not only the first: workers report all their files again after a
                // reload, so a directory whose watch was lost or failed is watched again then
                if let Some(directory) = path.parent()
                    && !covered(directory, &roots)
                {
                    watch_directory(&mut watcher, &mut directories, &mut failed, directory);
                }
                let groups = loaded.entry(path.clone()).or_insert(0);
                if *groups & group != 0 {
                    continue;
                }
                *groups |= group;
                if let std::collections::hash_map::Entry::Vacant(entry) = files.entry(path.clone()) {
                    // Reported only after the request that loaded it: saved since then, the worker may
                    // run the old content, and the fingerprint now would already be the new one
                    if std::fs::metadata(&path).and_then(|meta| meta.modified()).is_ok_and(|modified| modified >= since) {
                        entry.insert(None);
                        candidates.insert(path);
                        wait_for_more(&mut settle);
                    } else {
                        entry.insert(fingerprint(&path));
                    }
                }
            }
            Ok(Message::Roots(new, project)) => {
                // A changed .tenma-ignore reloads the hooks, like a file tenma.dev.php loaded
                let file = project.join(IGNORE);
                ignore = Ignore::read(&file, project);
                if loaded.insert(file.clone(), DEV).is_none() {
                    files.insert(file.clone(), fingerprint(&file));
                    if let Some(directory) = file.parent() {
                        watch_directory(&mut watcher, &mut directories, &mut failed, directory);
                    }
                }
                for root in new {
                    if roots.contains(&root) {
                        continue;
                    }
                    let mode = if root.1 { notify::RecursiveMode::Recursive } else { notify::RecursiveMode::NonRecursive };
                    // ponytail: a directory missing now is not watched once it appears; restart tenma
                    // (or touch tenma.dev.php) after creating it. Upgrade: watch the nearest parent.
                    if let Err(error) = watcher.watch(&root.0, mode) {
                        eprintln!("tenma: cannot watch {} for hooks: {error}", root.0.display());
                    }
                    roots.push(root);
                }
            }
            Ok(Message::Event(Ok(event))) if event.need_rescan() => {
                candidates.extend(files.keys().cloned());
                wait_for_more(&mut settle);
            }
            Ok(Message::Event(Ok(event))) => {
                // A watched directory deleted or moved away (git checkout, composer install): inotify
                // drops or keeps following its watch, so it is watched anew at the next report
                if matches!(event.kind, notify::EventKind::Remove(_) | notify::EventKind::Modify(notify::event::ModifyKind::Name(_))) {
                    for path in &event.paths {
                        if directories.remove(path) {
                            let _ = watcher.unwatch(path);
                        }
                    }
                }
                for path in event.paths {
                    if let Some(path) = relevant(&loaded, &roots, &ignore, path) {
                        candidates.insert(path);
                        wait_for_more(&mut settle);
                    }
                }
            }
            // Events were lost (inotify's queue overflowed, FSEvents says MustScanSubDirs, or an
            // error): every known file is compared again
            Ok(Message::Event(Err(error))) => {
                eprintln!("tenma: --watch: {error}, checking all files");
                candidates.extend(files.keys().cloned());
                wait_for_more(&mut settle);
            }
            Err(mpsc::RecvTimeoutError::Timeout) => {
                settle = None;
                // Compared only now that the writing has settled: no reload for a half-written file
                let mut batch = Changes::default();
                let mut hook_paths = Vec::new();
                for path in candidates.drain() {
                    let now = fingerprint(&path);
                    // A file seen for the first time counts as changed
                    if files.insert(path.clone(), now) == Some(now) {
                        continue;
                    }
                    let groups = loaded.get(&path).copied().unwrap_or(0);
                    if groups & APP != 0 {
                        batch.app.get_or_insert(path.clone());
                    }
                    if groups & DEV != 0 {
                        batch.dev.get_or_insert(path.clone());
                    }
                    if below(&roots, &path) && !ignore.ignored(&path) {
                        let modified = std::fs::metadata(&path).and_then(|meta| meta.modified()).ok();
                        hook_paths.push((path, modified.and_then(|time| time.duration_since(UNIX_EPOCH).ok()).map_or(0.0, |since| since.as_secs_f64())));
                    }
                }
                if !hook_paths.is_empty() {
                    let _ = hooks.send(hook_paths);
                }
                if (batch.app.is_some() || batch.dev.is_some()) && changes.send(batch).is_err() {
                    return;
                }
            }
            Err(mpsc::RecvTimeoutError::Disconnected) => return,
        }
    }
}

/// Watches a directory of loaded files unless it already is; a failure is reported once.
fn watch_directory(watcher: &mut notify::RecommendedWatcher, directories: &mut HashSet<PathBuf>, failed: &mut HashSet<PathBuf>, directory: &Path) {
    if directories.contains(directory) {
        return;
    }
    match watcher.watch(directory, notify::RecursiveMode::NonRecursive) {
        Ok(()) => {
            directories.insert(directory.to_path_buf());
        }
        // Gone meanwhile (a checkout in progress): the next report tries again
        Err(error) => {
            if directory.exists() && failed.insert(directory.to_path_buf()) {
                eprintln!("tenma: --watch cannot watch {}, its files do not reload: {error}", directory.display());
            }
        }
    }
}

/// Something to compare came: wait SETTLE for more, at most MAX_SETTLE since the first.
fn wait_for_more(settle: &mut Option<(Instant, Instant)>) {
    let now = Instant::now();
    let since = settle.map_or(now, |(since, _)| since);
    *settle = Some((since, (now + SETTLE).min(since + MAX_SETTLE)));
}

fn below(roots: &[(PathBuf, bool)], path: &Path) -> bool {
    roots.iter().any(|(root, recursive)| if *recursive { path.starts_with(root) } else { path.parent() == Some(root) })
}

/// The path of an event if it is a loaded file or an input below a hook directory. Events may
/// name it by another path than PHP (a symlinked directory such as /tmp on macOS): then the
/// resolved directory decides.
fn relevant(loaded: &HashMap<PathBuf, u8>, roots: &[(PathBuf, bool)], ignore: &Ignore, path: PathBuf) -> Option<PathBuf> {
    let wanted = |path: &Path| loaded.contains_key(path) || (below(roots, path) && !ignore.ignored(path));
    if wanted(&path) {
        return Some(path);
    }
    let resolved = path.parent()?.canonicalize().ok()?.join(path.file_name()?);
    wanted(&resolved).then_some(resolved)
}

const IGNORE: &str = ".tenma-ignore";

/// `.tenma-ignore` next to tenma.dev.php: what below the hooks' directories is no input, so that
/// a glob like `**/*.php` neither runs a hook for nor reads every file in `vendor/`. One entry per
/// line, `#` starts a comment. An entry with a slash is a path in the project (`storage/cache`),
/// one without matches any file or directory name (`node_modules`, `*.log`); `*` and `?` as in
/// globs. Without the file: .git, node_modules and vendor.
#[derive(Default)]
struct Ignore {
    project: PathBuf,
    /// Components of a path in the project
    paths: Vec<Vec<Vec<u8>>>,
    names: Vec<Vec<u8>>,
}

impl Ignore {
    fn read(file: &Path, project: PathBuf) -> Self {
        let text = std::fs::read(file).unwrap_or_else(|_| b".git\nnode_modules\nvendor\n".to_vec());
        let mut ignore = Ignore { project, ..Ignore::default() };
        for line in text.split(|&byte| byte == b'\n') {
            let entry = line.trim_ascii();
            if entry.is_empty() || entry.starts_with(b"#") {
                continue;
            }
            let entry = entry.strip_suffix(b"/").unwrap_or(entry);
            if entry.contains(&b'/') {
                let entry = entry.strip_prefix(b"/").unwrap_or(entry);
                ignore.paths.push(entry.split(|&byte| byte == b'/').map(<[u8]>::to_vec).collect());
            } else {
                ignore.names.push(entry.to_vec());
            }
        }
        ignore
    }

    fn ignored(&self, path: &Path) -> bool {
        let Ok(relative) = path.strip_prefix(&self.project) else {
            return false;
        };
        let parts: Vec<&[u8]> = relative.iter().map(|part| part.as_bytes()).collect();
        parts.iter().any(|part| self.names.iter().any(|name| wildcard(name, part)))
            || self.paths.iter().any(|entry| entry.len() <= parts.len() && entry.iter().zip(&parts).all(|(pattern, part)| wildcard(pattern, part)))
    }
}

/// `*` and `?` within a name.
fn wildcard(pattern: &[u8], text: &[u8]) -> bool {
    match (pattern.split_first(), text.split_first()) {
        (None, None) => true,
        (Some((b'*', rest)), _) => wildcard(rest, text) || (!text.is_empty() && wildcard(pattern, &text[1..])),
        (Some((b'?', rest)), Some((_, text_rest))) => wildcard(rest, text_rest),
        (Some((expected, rest)), Some((byte, text_rest))) => expected == byte && wildcard(rest, text_rest),
        _ => false,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn ignores_names_and_paths_in_the_project() {
        let directory = std::env::temp_dir().join(format!("tenma-ignore-{}", std::process::id()));
        std::fs::create_dir_all(&directory).unwrap();
        let file = directory.join(IGNORE);
        let defaults = Ignore::read(&file, "/p".into());
        assert!(defaults.ignored(Path::new("/p/vendor/a/b.php")) && defaults.ignored(Path::new("/p/x/node_modules/y.js")));
        assert!(!defaults.ignored(Path::new("/p/src/a.php")) && !defaults.ignored(Path::new("/elsewhere/vendor/a.php")));
        std::fs::write(&file, "# own list\n*.log\n/storage/c?che/\n").unwrap();
        let own = Ignore::read(&file, "/p".into());
        assert!(own.ignored(Path::new("/p/a/b.log")) && own.ignored(Path::new("/p/storage/cache/x")));
        assert!(!own.ignored(Path::new("/p/vendor/a.php")) && !own.ignored(Path::new("/p/src/storage/cache/x")));
        let _ = std::fs::remove_dir_all(directory);
    }

    #[test]
    fn a_rescan_compares_every_file() {
        let file = std::env::temp_dir().join(format!("tenma-rescan-{}.php", std::process::id()));
        std::fs::write(&file, "<?php // one").unwrap();
        // A watcher that drops its own events: only the rescan below can find the change
        let watcher = notify::recommended_watcher(|_| {}).unwrap();
        let (sender, messages) = mpsc::channel();
        let (changes, mut receiver) = unbounded_channel();
        let (hooks, _hook_receiver) = unbounded_channel();
        std::thread::spawn(move || watch(watcher, &messages, &changes, &hooks));
        sender.send(Message::Loaded(file.clone(), APP, SystemTime::now() + Duration::from_secs(3600))).unwrap();
        // The watcher thread takes its fingerprint first
        std::thread::sleep(Duration::from_millis(100));
        std::fs::write(&file, "<?php // two").unwrap();
        sender.send(Message::Event(Ok(notify::Event::new(notify::EventKind::Other).set_flag(notify::event::Flag::Rescan)))).unwrap();
        let started = Instant::now();
        let changed = loop {
            match receiver.try_recv() {
                Ok(changed) => break changed.app,
                Err(_) if started.elapsed() < Duration::from_secs(3) => std::thread::sleep(Duration::from_millis(20)),
                Err(_) => break None,
            }
        };
        let _ = std::fs::remove_file(&file);
        assert_eq!(changed, Some(file));
    }
}

fn fingerprint(path: &Path) -> Option<u64> {
    let content = std::fs::read(path).ok()?;
    let mut hasher = std::hash::DefaultHasher::new();
    content.hash(&mut hasher);
    Some(hasher.finish())
}
