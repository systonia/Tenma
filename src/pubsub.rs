//! Messages between workers: Tenma::publish() hands a message to every subscription of its channel,
//! in every worker of this process, the publishing one included. A subscription is a channel per
//! worker; PHP fetches the next message with an op and runs its callback in a fiber.

use std::cell::RefCell;
use std::collections::HashMap;
use std::rc::Rc;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{LazyLock, Mutex};

use tokio::sync::mpsc::{UnboundedReceiver, UnboundedSender, unbounded_channel};

use crate::ops;
use crate::php::{OpResult, bytes};

type Receiver = Rc<RefCell<Option<UnboundedReceiver<Vec<u8>>>>>;

/// Every subscription of the process by channel: its ID and where its messages go.
// ponytail: unbounded per subscription; a callback slower than the publishers lets its queue grow
static CHANNELS: LazyLock<Mutex<HashMap<Vec<u8>, Vec<(u64, UnboundedSender<Vec<u8>>)>>>> = LazyLock::new(Mutex::default);
/// Unique across workers: unsubscribe finds the subscription in CHANNELS by it.
static NEXT_ID: AtomicU64 = AtomicU64::new(1);

thread_local! {
    /// This worker's subscriptions: ID → channel and receiver.
    static SUBSCRIPTIONS: RefCell<HashMap<u64, (Vec<u8>, Receiver)>> = RefCell::new(HashMap::new());
}

/// Hands the message to every subscription of the channel in this process.
pub fn deliver(channel: &[u8], message: &[u8]) {
    let mut channels = CHANNELS.lock().unwrap_or_else(std::sync::PoisonError::into_inner);
    if let Some(subscriptions) = channels.get_mut(channel) {
        // A worker that went away without unsubscribing (restart after a fatal error) closed its receivers
        subscriptions.retain(|(_, sender)| sender.send(message.to_vec()).is_ok());
    }
}

fn subscribe(channel: &[u8]) -> u64 {
    let id = NEXT_ID.fetch_add(1, Ordering::Relaxed);
    let (sender, receiver) = unbounded_channel();
    CHANNELS.lock().unwrap_or_else(std::sync::PoisonError::into_inner).entry(channel.to_vec()).or_default().push((id, sender));
    SUBSCRIPTIONS.with_borrow_mut(|subscriptions| subscriptions.insert(id, (channel.to_vec(), Rc::new(RefCell::new(Some(receiver))))));
    id
}

fn unsubscribe(id: u64) {
    let Some((channel, _)) = SUBSCRIPTIONS.with_borrow_mut(|subscriptions| subscriptions.remove(&id)) else {
        return;
    };
    let mut channels = CHANNELS.lock().unwrap_or_else(std::sync::PoisonError::into_inner);
    if let Some(subscriptions) = channels.get_mut(&channel) {
        subscriptions.retain(|(other, _)| *other != id);
        if subscriptions.is_empty() {
            channels.remove(&channel);
        }
    }
}

/// The next message of a subscription; an op, its result is the message.
fn receive(id: u64) -> u64 {
    let Some(slot) = SUBSCRIPTIONS.with_borrow(|subscriptions| subscriptions.get(&id).map(|(_, receiver)| Rc::clone(receiver))) else {
        return 0;
    };
    ops::spawn(async move {
        let Some(receiver) = slot.borrow_mut().take() else {
            return OpResult::Error("this subscription is already receiving".into());
        };
        let mut lent = Lent { slot, receiver: Some(receiver) };
        // None once unsubscribed: the sender is gone
        lent.receiver.as_mut().unwrap().recv().await.map_or(OpResult::Null, OpResult::Text)
    })
}

/// A receiver taken out for one receive(): goes back when the op ends, also when it is cancelled.
struct Lent {
    slot: Receiver,
    receiver: Option<UnboundedReceiver<Vec<u8>>>,
}

impl Drop for Lent {
    fn drop(&mut self) {
        *self.slot.borrow_mut() = self.receiver.take();
    }
}

/// The worker's interpreter ends: its subscriptions end with it.
pub fn clear() {
    let ids: Vec<u64> = SUBSCRIPTIONS.with_borrow(|subscriptions| subscriptions.keys().copied().collect());
    for id in ids {
        unsubscribe(id);
    }
}

/// tenma_pubsub(string $op, int $id, string $channel, string $message): int.
/// "publish": 1. "subscribe": the subscription's ID. "receive": an op for the next message of
/// subscription $id, 0 outside the event loop. "unsubscribe": 1.
#[unsafe(no_mangle)]
extern "C" fn tenma_rs_pubsub(op: *const u8, op_len: usize, id: u64, channel: *const u8, channel_len: usize, message: *const u8, message_len: usize) -> u64 {
    let channel = bytes(channel, channel_len);
    match bytes(op, op_len) {
        b"publish" => {
            deliver(channel, bytes(message, message_len));
            1
        }
        b"subscribe" => subscribe(channel),
        b"receive" => receive(id),
        b"unsubscribe" => {
            unsubscribe(id);
            1
        }
        _ => 0,
    }
}
