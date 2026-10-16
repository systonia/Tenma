//! Streaming responses and WebSockets for PHP. Both belong to a request running in a fiber;
//! Rust holds the channels, PHP talks to them through the C layer.

use std::cell::{Cell, RefCell};
use std::collections::HashMap;
use std::ffi::c_int;
use std::pin::Pin;
use std::rc::Rc;
use std::sync::Arc;
use std::sync::atomic::{AtomicBool, Ordering};
use std::task::{Context, Poll};

use bytes::Bytes;
use futures_util::{SinkExt, StreamExt};
use hyper::body::{Body, Frame};
use hyper::upgrade::OnUpgrade;
use hyper_util::rt::TokioIo;
use tokio::sync::mpsc;
use tokio_tungstenite::WebSocketStream;
use tokio_tungstenite::tungstenite::Message;
use tokio_tungstenite::tungstenite::protocol::frame::coding::CloseCode;
use tokio_tungstenite::tungstenite::protocol::{CloseFrame, Role, WebSocketConfig};

use crate::ops;
use crate::php::{self, OpResult, Reply, bytes};

/// This many chunks may wait for a slow client, then the fiber waits when writing.
const STREAM_BUFFER: usize = 16;
/// This many messages may wait in either direction. PHP not reading: tenma disconnects the client.
/// The client not reading: send() waits.
const SOCKET_BACKLOG: usize = 256;
/// Largest WebSocket message in bytes, like --max-body.
pub static MAX_MESSAGE: std::sync::atomic::AtomicUsize = std::sync::atomic::AtomicUsize::new(10 << 20);

/// Body of a streaming response: what PHP writes, as soon as it is there. Ends when the channel
/// closes; unless the writer finished, with an error, so hyper aborts the connection instead of
/// sending the last chunk and the client sees that the response was cut off.
pub struct ChannelBody {
    chunks: mpsc::Receiver<Bytes>,
    finished: Arc<AtomicBool>,
    /// Cut off and polled once more: hyper has flushed what came before, now the error may follow.
    flushed: bool,
    /// The request of a PHP stream: dropped while PHP still writes, it learns that the client left.
    rid: Option<u64>,
}

impl Drop for ChannelBody {
    fn drop(&mut self) {
        if let Some(rid) = self.rid
            && STREAMS.with_borrow_mut(|streams| streams.remove(&rid)).is_some()
        {
            php::gone(rid);
        }
    }
}

impl ChannelBody {
    /// A body whose end is checked by its Content-Length, as for files.
    pub fn sized(chunks: mpsc::Receiver<Bytes>) -> Self {
        Self { chunks, finished: Arc::new(AtomicBool::new(true)), flushed: false, rid: None }
    }
}

impl Body for ChannelBody {
    type Data = Bytes;
    type Error = std::io::Error;

    fn poll_frame(mut self: Pin<&mut Self>, cx: &mut Context<'_>) -> Poll<Option<Result<Frame<Bytes>, std::io::Error>>> {
        match self.chunks.poll_recv(cx) {
            Poll::Ready(Some(bytes)) => Poll::Ready(Some(Ok(Frame::data(bytes)))),
            Poll::Ready(None) if self.finished.load(Ordering::Relaxed) => Poll::Ready(None),
            // hyper drops what it has not flushed when the body fails: first a round in which it
            // flushes (it does after every write attempt), then the error.
            // ponytail: if the client's socket is full right then, the rest of the buffer is lost too
            Poll::Ready(None) if !self.flushed => {
                self.flushed = true;
                cx.waker().wake_by_ref();
                Poll::Pending
            }
            Poll::Ready(None) => Poll::Ready(Some(Err(std::io::Error::other("the stream was cut off")))),
            Poll::Pending => Poll::Pending,
        }
    }
}

/// The channels of a WebSocket, as the HTTP part gets them for the upgrade.
pub struct Pipes {
    outgoing: mpsc::Receiver<Message>,
    incoming: mpsc::Sender<Vec<u8>>,
    /// Bytes of the incoming messages PHP has not read yet.
    unread: Rc<Cell<usize>>,
    gone: Gone,
}

/// Dropped when the connection ends: a handler waiting for something other than receive() learns
/// that the client is gone, as with streams. One in receive() gets null instead and ends itself.
struct Gone(u64);

impl Drop for Gone {
    fn drop(&mut self) {
        let receiving = SOCKETS.with_borrow(|sockets| sockets.get(&self.0).map(|socket| socket.incoming.borrow().is_none()));
        if receiving == Some(false) {
            SOCKETS.with_borrow_mut(|sockets| sockets.remove(&self.0));
            php::gone(self.0);
        }
    }
}

/// The PHP side of a WebSocket.
struct Socket {
    outgoing: mpsc::Sender<Message>,
    /// Held by the op of a running receive(), otherwise here.
    incoming: Rc<RefCell<Option<mpsc::Receiver<Vec<u8>>>>>,
    unread: Rc<Cell<usize>>,
}

thread_local! {
    /// A stream's channel, and whether its writer finished.
    static STREAMS: RefCell<HashMap<u64, (mpsc::Sender<Bytes>, Arc<AtomicBool>)>> = RefCell::new(HashMap::new());
    static SOCKETS: RefCell<HashMap<u64, Socket>> = RefCell::new(HashMap::new());
}

/// Forgets all streams and sockets of the interpreter when it restarts after a fatal error: what they
/// sent so far is all, so their clients see them cut off.
pub fn clear() {
    STREAMS.with_borrow_mut(HashMap::clear);
    SOCKETS.with_borrow_mut(|sockets| sockets.drain().for_each(|(_, socket)| fail(&socket)));
}

/// Closes a WebSocket with 1011: the server broke off.
fn fail(socket: &Socket) {
    let _ = socket.outgoing.try_send(Message::Close(Some(CloseFrame { code: CloseCode::Error, reason: "Internal error".into() })));
}


/// Sends the head of the response; the body follows with tenma_rs_stream_write. 0 if the request is not waiting.
#[unsafe(no_mangle)]
extern "C" fn tenma_rs_stream_start(rid: u64, status: u16, headers: *const u8, headers_len: usize) -> c_int {
    let (sender, chunks) = mpsc::channel(STREAM_BUFFER);
    let finished = Arc::new(AtomicBool::new(false));
    let headers = php::parse_header_lines(bytes(headers, headers_len));
    if !php::reply(rid, Reply::Stream { status, headers, body: ChannelBody { chunks, finished: Arc::clone(&finished), flushed: false, rid: Some(rid) } }) {
        return 0;
    }
    STREAMS.with_borrow_mut(|streams| streams.insert(rid, (sender, finished)));
    1
}

/// 0: written. Above 0: buffer full, an op the fiber must wait on. -1: client is gone.
#[unsafe(no_mangle)]
extern "C" fn tenma_rs_stream_write(rid: u64, chunk: *const u8, len: usize) -> i64 {
    let Some(sender) = STREAMS.with_borrow(|streams| streams.get(&rid).map(|(sender, _)| sender.clone())) else {
        return -1;
    };
    match sender.try_send(Bytes::copy_from_slice(bytes(chunk, len))) {
        Ok(()) => 0,
        Err(mpsc::error::TrySendError::Full(chunk)) => match ops::spawn(async move {
            match sender.send(chunk).await {
                Ok(()) => OpResult::Null,
                Err(_) => OpResult::Error("connection closed".into()),
            }
        }) {
            0 => -1,
            op => op as i64,
        },
        Err(mpsc::error::TrySendError::Closed(_)) => {
            STREAMS.with_borrow_mut(|streams| streams.remove(&rid));
            -1
        }
    }
}

/// $finished: the writer returned; otherwise (an error, exit()) the client sees the stream cut off.
#[unsafe(no_mangle)]
extern "C" fn tenma_rs_stream_end(rid: u64, finished: c_int) {
    if let Some((_, flag)) = STREAMS.with_borrow_mut(|streams| streams.remove(&rid)) {
        flag.store(finished != 0, Ordering::Relaxed);
    }
}

/// Accepts the WebSocket: the request responds with 101, after that the messages belong to PHP.
#[unsafe(no_mangle)]
extern "C" fn tenma_rs_ws_accept(rid: u64) -> c_int {
    let (outgoing, outgoing_receiver) = mpsc::channel(SOCKET_BACKLOG);
    let (incoming_sender, incoming) = mpsc::channel(SOCKET_BACKLOG);
    let unread = Rc::new(Cell::new(0));
    if !php::reply(rid, Reply::Upgrade(Pipes { outgoing: outgoing_receiver, incoming: incoming_sender, unread: Rc::clone(&unread), gone: Gone(rid) })) {
        return 0;
    }
    let socket = Socket { outgoing, incoming: Rc::new(RefCell::new(Some(incoming))), unread };
    SOCKETS.with_borrow_mut(|sockets| sockets.insert(rid, socket));
    1
}

/// 0: on its way. Above 0: SOCKET_BACKLOG messages wait already, an op the fiber must wait on, as
/// for streams. -1: socket closed. A client that stops reading stalls the write to it and is
/// disconnected after --request-timeout (WriteTimeout), which ends the op too.
#[unsafe(no_mangle)]
extern "C" fn tenma_rs_ws_send(rid: u64, text: *const u8, len: usize) -> i64 {
    let Some(sender) = SOCKETS.with_borrow(|sockets| sockets.get(&rid).map(|socket| socket.outgoing.clone())) else {
        return -1;
    };
    // Text if it is UTF-8, otherwise a binary frame: bytes arrive as they were sent
    let data = bytes(text, len).to_vec();
    let message = match String::from_utf8(data) {
        Ok(text) => Message::text(text),
        Err(error) => Message::binary(error.into_bytes()),
    };
    match sender.try_send(message) {
        Ok(()) => 0,
        Err(mpsc::error::TrySendError::Full(message)) => match ops::spawn(async move {
            match sender.send(message).await {
                Ok(()) => OpResult::Null,
                Err(_) => OpResult::Error("connection closed".into()),
            }
        }) {
            0 => -1,
            op => op as i64,
        },
        Err(mpsc::error::TrySendError::Closed(_)) => {
            SOCKETS.with_borrow_mut(|sockets| sockets.remove(&rid));
            -1
        }
    }
}

/// Op for the next message: a string, or null if the client has closed. 0: no socket.
#[unsafe(no_mangle)]
extern "C" fn tenma_rs_ws_receive(rid: u64) -> u64 {
    let Some((incoming, unread)) = SOCKETS.with_borrow(|sockets| sockets.get(&rid).map(|socket| (Rc::clone(&socket.incoming), Rc::clone(&socket.unread)))) else {
        return 0;
    };
    ops::spawn(async move {
        let Some(receiver) = incoming.borrow_mut().take() else {
            return OpResult::Error("receive() is already running on this WebSocket".into());
        };
        let mut lent = Lent { slot: incoming, receiver: Some(receiver) };
        let message = lent.receiver.as_mut().unwrap().recv().await;
        message.map_or(OpResult::Null, |message| {
            unread.set(unread.get() - message.len());
            OpResult::Text(message)
        })
    })
}

/// A receiver taken out for one receive(): goes back when the op ends, also when it is cancelled.
struct Lent {
    slot: Rc<RefCell<Option<mpsc::Receiver<Vec<u8>>>>>,
    receiver: Option<mpsc::Receiver<Vec<u8>>>,
}

impl Drop for Lent {
    fn drop(&mut self) {
        *self.slot.borrow_mut() = self.receiver.take();
    }
}

/// $finished: a normal close; otherwise (an error in the handler, exit()) the client gets 1011.
#[unsafe(no_mangle)]
extern "C" fn tenma_rs_ws_close(rid: u64, finished: c_int) {
    if let Some(socket) = SOCKETS.with_borrow_mut(|sockets| sockets.remove(&rid))
        && finished == 0
    {
        fail(&socket);
    }
}

/// After the 101 response: pass messages between client and PHP until one side closes.
pub async fn run_websocket(upgrade: OnUpgrade, pipes: Pipes) {
    let Pipes { mut outgoing, incoming, unread, gone: _gone } = pipes;
    let Ok(upgraded) = upgrade.await else {
        return;
    };
    let limit = MAX_MESSAGE.load(std::sync::atomic::Ordering::Relaxed);
    let config = WebSocketConfig::default().max_message_size(Some(limit)).max_frame_size(Some(limit));
    let socket = WebSocketStream::from_raw_socket(TokioIo::new(upgraded), Role::Server, Some(config)).await;
    let (mut sink, mut stream) = socket.split();
    loop {
        tokio::select! {
            message = stream.next() => match message {
                Some(Ok(Message::Text(text))) => {
                    if !accept_message(&incoming, &unread, limit, text.as_bytes().to_vec(), &mut sink).await {
                        break;
                    }
                }
                Some(Ok(Message::Binary(data))) => {
                    if !accept_message(&incoming, &unread, limit, data.to_vec(), &mut sink).await {
                        break;
                    }
                }
                Some(Ok(_)) => {}
                Some(Err(_)) | None => break,
            },
            message = outgoing.recv() => match message {
                Some(message) => {
                    if sink.send(message).await.is_err() {
                        break;
                    }
                }
                None => {
                    let _ = sink.close().await;
                    break;
                }
            },
        }
    }
}

/// Passes a message on to PHP. If PHP does not keep reading, the backlog does not grow without end:
/// at SOCKET_BACKLOG unread messages or more than $limit unread bytes (one message is at most that)
/// tenma disconnects the client. False means: end the connection.
async fn accept_message(incoming: &mpsc::Sender<Vec<u8>>, unread: &Cell<usize>, limit: usize, message: Vec<u8>, sink: &mut (impl SinkExt<Message> + Unpin)) -> bool {
    let size = message.len();
    let sent = if !incoming.is_closed() && unread.get() + size > limit { Err(mpsc::error::TrySendError::Full(message)) } else { incoming.try_send(message) };
    match sent {
        Ok(()) => {
            unread.set(unread.get() + size);
            true
        }
        // PHP no longer reads messages (handler done or only sending): dropping them is right here
        Err(mpsc::error::TrySendError::Closed(_)) => true,
        Err(mpsc::error::TrySendError::Full(_)) => {
            let reason = "Too many unread messages";
            let _ = sink.send(Message::Close(Some(CloseFrame { code: CloseCode::Policy, reason: reason.into() }))).await;
            false
        }
    }
}
