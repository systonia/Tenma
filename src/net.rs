//! Waiting for sockets PHP owns. PHP's stream transports (tcp, unix, ssl, tls) keep their file
//! descriptors and their OpenSSL state; the C layer only puts the descriptor into non-blocking mode and,
//! wherever PHP would poll(), starts one of these ops instead: the fiber waits until the descriptor is
//! readable or writable, and other requests of the worker run meanwhile. This is what makes mysqlnd,
//! fsockopen() and the http:// wrapper asynchronous under tenma.

use std::os::fd::{AsRawFd, RawFd};
use std::time::Duration;

use tokio::io::Interest;
use tokio::io::unix::AsyncFd;
use tokio::task::JoinSet;

use crate::ops;
use crate::php::OpResult;

/// A descriptor PHP owns: registering it with tokio's reactor does not close it.
struct Borrowed(RawFd);

impl AsRawFd for Borrowed {
    fn as_raw_fd(&self) -> RawFd {
        self.0
    }
}

/// POLLIN and POLLPRI as readable, POLLOUT as writable, both bits as both.
fn interest(events: i32) -> Interest {
    let readable = events & (libc::POLLIN | libc::POLLPRI) as i32 != 0;
    let writable = events & libc::POLLOUT as i32 != 0;
    match (readable, writable) {
        (true, true) => Interest::READABLE | Interest::WRITABLE,
        (_, true) => Interest::WRITABLE,
        _ => Interest::READABLE,
    }
}

/// Waits until `fd` is ready for `events`, or `timeout_ms` has passed (-1: never). Completes with
/// "ready" or "timeout".
async fn wait(fd: RawFd, events: i32, timeout_ms: i64) -> OpResult {
    let interest = interest(events);
    let fd = match AsyncFd::with_interest(Borrowed(fd), interest) {
        Ok(fd) => fd,
        Err(error) => return OpResult::Error(format!("cannot watch socket: {error}")),
    };
    let ready = fd.ready(interest);
    let ready = if timeout_ms < 0 {
        ready.await.is_ok()
    } else {
        match tokio::time::timeout(Duration::from_millis(timeout_ms as u64), ready).await {
            Ok(ready) => ready.is_ok(),
            Err(_) => return OpResult::Text(b"timeout".to_vec()),
        }
    };
    if ready {
        OpResult::Text(b"ready".to_vec())
    } else {
        OpResult::Error("cannot watch socket".into())
    }
}

/// One descriptor; the op's value is "ready" or "timeout".
#[unsafe(no_mangle)]
extern "C" fn tenma_rs_wait_fd(fd: i32, events: i32, timeout_ms: i64) -> u64 {
    ops::spawn(
        "net",
        format!("wait {fd} {events}"),
        wait(fd, events, timeout_ms),
    )
}

/// stream_select(): several descriptors with their events, `count` of each. The op's value has one
/// byte per descriptor: the sum of 1 (readable) and 2 (writable) as a digit, "0" where nothing
/// happened; all zeros after the timeout.
#[unsafe(no_mangle)]
extern "C" fn tenma_rs_select(
    fds: *const i32,
    events: *const i32,
    count: usize,
    timeout_ms: i64,
) -> u64 {
    let fds = unsafe { std::slice::from_raw_parts(fds, count) }.to_vec();
    let events = unsafe { std::slice::from_raw_parts(events, count) }.to_vec();
    ops::spawn("net", format!("select {fds:?}"), async move {
        let mut set = JoinSet::new();
        for (index, (&fd, &events)) in fds.iter().zip(&events).enumerate() {
            let interest = interest(events);
            set.spawn_local(async move {
                let fd = AsyncFd::with_interest(Borrowed(fd), interest).ok()?;
                let guard = fd.ready(interest).await.ok()?;
                let ready = guard.ready();
                let mut mask = 0;
                if ready.is_readable() || ready.is_read_closed() {
                    mask |= 1;
                }
                if ready.is_writable() || ready.is_write_closed() {
                    mask |= 2;
                }
                Some((index, mask))
            });
        }
        let mut result = vec![b'0'; count];
        let first = if timeout_ms < 0 {
            set.join_next().await
        } else {
            match tokio::time::timeout(Duration::from_millis(timeout_ms as u64), set.join_next())
                .await
            {
                Ok(first) => first,
                Err(_) => return OpResult::Text(result),
            }
        };
        let mut done = vec![first];
        // Whatever else is ready by now counts too, as select() would report it
        while let Some(next) = set.try_join_next() {
            done.push(Some(next));
        }
        for finished in done.into_iter().flatten() {
            if let Ok(Some((index, mask))) = finished {
                result[index] = b'0' + mask;
            }
        }
        OpResult::Text(result)
    })
}
