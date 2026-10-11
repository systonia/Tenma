//! Streaming responses and WebSockets for PHP. Both belong to a request running in a fiber;
//! Rust holds the channels, PHP talks to them through the C layer.

use std::cell::RefCell;
use std::collections::HashMap;
use std::convert::Infallible;
use std::ffi::c_int;
use std::pin::Pin;
use std::rc::Rc;
use std::task::{Context, Poll};

use bytes::Bytes;
use futures_util::{SinkExt, StreamExt};
use hyper::body::{Body, Frame};
use hyper::upgrade::OnUpgrade;
use hyper_util::rt::TokioIo;
use tokio::sync::mpsc::{self, UnboundedReceiver, UnboundedSender, unbounded_channel};
use tokio_tungstenite::WebSocketStream;
use tokio_tungstenite::tungstenite::Message;
use tokio_tungstenite::tungstenite::protocol::frame::coding::CloseCode;
use tokio_tungstenite::tungstenite::protocol::{CloseFrame, Role, WebSocketConfig};

use crate::ops;
use crate::php::{self, OpResult, Reply, bytes};

/// This many chunks may wait for a slow client, then the fiber waits when writing.
const STREAM_BUFFER: usize = 16;
/// This many messages a client may send that PHP has not read yet; after that tenma disconnects.
const SOCKET_BACKLOG: usize = 256;
/// Largest WebSocket message in bytes, like --max-body.
pub static MAX_MESSAGE: std::sync::atomic::AtomicUsize = std::sync::atomic::AtomicUsize::new(10 << 20);

/// Body of a streaming response: what PHP writes, as soon as it is there. Ends when PHP closes the channel.
pub struct ChannelBody(pub mpsc::Receiver<Bytes>);

impl Body for ChannelBody {
    type Data = Bytes;
    type Error = Infallible;

    fn poll_frame(mut self: Pin<&mut Self>, cx: &mut Context<'_>) -> Poll<Option<Result<Frame<Bytes>, Infallible>>> {
        self.0.poll_recv(cx).map(|chunk| chunk.map(|bytes| Ok(Frame::data(bytes))))
    }
}

/// The channels of a WebSocket, as the HTTP part gets them for the upgrade.
pub struct Pipes {
    outgoing: UnboundedReceiver<Message>,
    incoming: mpsc::Sender<Vec<u8>>,
}

/// The PHP side of a WebSocket.
struct Socket {
    outgoing: UnboundedSender<Message>,
    /// Held by the op of a running receive(), otherwise here.
    incoming: Rc<RefCell<Option<mpsc::Receiver<Vec<u8>>>>>,
}

thread_local! {
    static STREAMS: RefCell<HashMap<u64, mpsc::Sender<Bytes>>> = RefCell::new(HashMap::new());
    static SOCKETS: RefCell<HashMap<u64, Socket>> = RefCell::new(HashMap::new());
}

/// Forgets all streams and sockets of the interpreter, e.g. when a worker restarts.
pub fn clear() {
    STREAMS.with_borrow_mut(HashMap::clear);
    SOCKETS.with_borrow_mut(HashMap::clear);
}


/// Sends the head of the response; the body follows with tenma_rs_stream_write. 0 if the request is not waiting.
#[unsafe(no_mangle)]
extern "C" fn tenma_rs_stream_start(rid: u64, status: u16, headers: *const u8, headers_len: usize) -> c_int {
    let (sender, body) = mpsc::channel(STREAM_BUFFER);
    let headers = php::parse_header_lines(bytes(headers, headers_len));
    if !php::reply(rid, Reply::Stream { status, headers, body }) {
        return 0;
    }
    STREAMS.with_borrow_mut(|streams| streams.insert(rid, sender));
    1
}

/// 0: written. Above 0: buffer full, an op the fiber must wait on. -1: client is gone.
#[unsafe(no_mangle)]
extern "C" fn tenma_rs_stream_write(rid: u64, chunk: *const u8, len: usize) -> i64 {
    let Some(sender) = STREAMS.with_borrow(|streams| streams.get(&rid).cloned()) else {
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

#[unsafe(no_mangle)]
extern "C" fn tenma_rs_stream_end(rid: u64) {
    STREAMS.with_borrow_mut(|streams| streams.remove(&rid));
}

/// Accepts the WebSocket: the request responds with 101, after that the messages belong to PHP.
#[unsafe(no_mangle)]
extern "C" fn tenma_rs_ws_accept(rid: u64) -> c_int {
    let (outgoing, outgoing_receiver) = unbounded_channel();
    let (incoming_sender, incoming) = mpsc::channel(SOCKET_BACKLOG);
    if !php::reply(rid, Reply::Upgrade(Pipes { outgoing: outgoing_receiver, incoming: incoming_sender })) {
        return 0;
    }
    let socket = Socket { outgoing, incoming: Rc::new(RefCell::new(Some(incoming))) };
    SOCKETS.with_borrow_mut(|sockets| sockets.insert(rid, socket));
    1
}

/// 1: on its way. 0: socket closed.
#[unsafe(no_mangle)]
extern "C" fn tenma_rs_ws_send(rid: u64, text: *const u8, len: usize) -> c_int {
    let message = Message::text(String::from_utf8_lossy(bytes(text, len)).into_owned());
    SOCKETS.with_borrow(|sockets| sockets.get(&rid).is_some_and(|socket| socket.outgoing.send(message).is_ok())).into()
}

/// Op for the next message: a string, or null if the client has closed. 0: no socket.
#[unsafe(no_mangle)]
extern "C" fn tenma_rs_ws_receive(rid: u64) -> u64 {
    let Some(incoming) = SOCKETS.with_borrow(|sockets| sockets.get(&rid).map(|socket| Rc::clone(&socket.incoming))) else {
        return 0;
    };
    ops::spawn(async move {
        let Some(receiver) = incoming.borrow_mut().take() else {
            return OpResult::Error("receive() is already running on this WebSocket".into());
        };
        let mut lent = Lent { slot: incoming, receiver: Some(receiver) };
        let message = lent.receiver.as_mut().unwrap().recv().await;
        message.map_or(OpResult::Null, OpResult::Text)
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

#[unsafe(no_mangle)]
extern "C" fn tenma_rs_ws_close(rid: u64) {
    SOCKETS.with_borrow_mut(|sockets| sockets.remove(&rid));
}

/// After the 101 response: pass messages between client and PHP until one side closes.
pub async fn run_websocket(upgrade: OnUpgrade, pipes: Pipes) {
    let Pipes { mut outgoing, incoming } = pipes;
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
                    if !accept_message(&incoming, text.as_bytes().to_vec(), &mut sink).await {
                        break;
                    }
                }
                Some(Ok(Message::Binary(data))) => {
                    if !accept_message(&incoming, data.to_vec(), &mut sink).await {
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
/// at SOCKET_BACKLOG unread messages tenma disconnects the client. False means: end the connection.
async fn accept_message(incoming: &mpsc::Sender<Vec<u8>>, message: Vec<u8>, sink: &mut (impl SinkExt<Message> + Unpin)) -> bool {
    match incoming.try_send(message) {
        Ok(()) => true,
        // PHP no longer reads messages (handler done or only sending): dropping them is right here
        Err(mpsc::error::TrySendError::Closed(_)) => true,
        Err(mpsc::error::TrySendError::Full(_)) => {
            let reason = "Too many unread messages";
            let _ = sink.send(Message::Close(Some(CloseFrame { code: CloseCode::Policy, reason: reason.into() }))).await;
            false
        }
    }
}
