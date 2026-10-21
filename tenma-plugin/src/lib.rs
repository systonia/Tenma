//! The C ABI of tenma's native plugins (`tenma_plugin.h`, ABI version 1), as Rust types, and safe
//! wrappers around the API table tenma hands a plugin.
//!
//! A plugin is a `cdylib` that exports `tenma_plugin_init`:
//!
//! ```ignore
//! use tenma_plugin::{Api, Plugin, Reply, Token, ABI};
//!
//! static PLUGIN: Plugin = Plugin {
//!     name: c"echo".as_ptr(),
//!     call: Some(call),
//!     call_async: None,
//!     adopt: None,
//!     message: None,
//!     closed: None,
//!     shutdown: None,
//! };
//!
//! #[unsafe(no_mangle)]
//! extern "C" fn tenma_plugin_init(api: &'static Api, abi: u32) -> *const Plugin {
//!     if abi != ABI {
//!         return std::ptr::null();
//!     }
//!     tenma_plugin::set_api(api);
//!     &PLUGIN
//! }
//!
//! extern "C" fn call(function: *const u8, len: usize, input: *const u8, input_len: usize, out: *mut Reply) -> i32 {
//!     let (function, input) = unsafe { (tenma_plugin::slice(function, len), tenma_plugin::slice(input, input_len)) };
//!     tenma_plugin::api().reply(out, input);
//!     0
//! }
//! ```

use std::ffi::c_char;
use std::sync::OnceLock;

pub const ABI: u32 = 1;

pub const SENT: i32 = 0;
pub const FULL: i32 = 1;
pub const CLOSED: i32 = 2;

pub const LOG_ERROR: i32 = 0;
pub const LOG_WARNING: i32 = 1;
pub const LOG_INFO: i32 = 2;

/// The result buffer of a synchronous call; filled with `Api::reply`.
#[repr(C)]
pub struct Reply {
    _private: [u8; 0],
}

/// The token of an asynchronous call; completed exactly once with `Api::complete`.
#[repr(C)]
pub struct Token {
    _private: [u8; 0],
}

/// tenma to plugin: the table `tenma_plugin_init` receives, valid for the life of the process.
#[repr(C)]
pub struct Api {
    pub send: extern "C" fn(conn: u64, ptr: *const u8, len: usize, binary: i32) -> i32,
    pub buffered: extern "C" fn(conn: u64) -> usize,
    pub close: extern "C" fn(conn: u64, code: u16),
    pub post: extern "C" fn(channel: *const c_char, channel_len: usize, ptr: *const u8, len: usize),
    pub complete: extern "C" fn(token: *mut Token, ptr: *const u8, len: usize, error: i32),
    pub reply: extern "C" fn(out: *mut Reply, ptr: *const u8, len: usize),
    pub log: extern "C" fn(level: i32, ptr: *const c_char, len: usize),
}

/// Plugin to tenma: what `tenma_plugin_init` returns.
#[repr(C)]
pub struct Plugin {
    pub name: *const c_char,
    pub call: Option<
        extern "C" fn(
            fn_: *const c_char,
            fn_len: usize,
            input: *const u8,
            input_len: usize,
            out: *mut Reply,
        ) -> i32,
    >,
    pub call_async: Option<
        extern "C" fn(
            fn_: *const c_char,
            fn_len: usize,
            input: *const u8,
            input_len: usize,
            token: *mut Token,
        ),
    >,
    pub adopt: Option<extern "C" fn(conn: u64)>,
    pub message: Option<extern "C" fn(conn: u64, ptr: *const u8, len: usize, binary: i32)>,
    pub closed: Option<extern "C" fn(conn: u64)>,
    pub shutdown: Option<extern "C" fn()>,
}

unsafe impl Sync for Plugin {}

static API: OnceLock<&'static Api> = OnceLock::new();

/// Keeps the table for `api()`; call it in `tenma_plugin_init`.
pub fn set_api(api: &'static Api) {
    let _ = API.set(api);
}

/// The table tenma handed over.
pub fn api() -> &'static Api {
    API.get().expect("tenma_plugin::set_api was not called")
}

/// Bytes from a pointer and length; an empty slice for a null pointer.
///
/// # Safety
/// `ptr` must point to `len` readable bytes, or be null.
pub unsafe fn slice<'a>(ptr: *const u8, len: usize) -> &'a [u8] {
    if ptr.is_null() || len == 0 {
        &[]
    } else {
        unsafe { std::slice::from_raw_parts(ptr, len) }
    }
}

impl Api {
    /// Queues a message on an adopted connection: `SENT`, `FULL` or `CLOSED`.
    pub fn send(&self, conn: u64, message: &[u8], binary: bool) -> i32 {
        (self.send)(conn, message.as_ptr(), message.len(), i32::from(binary))
    }

    pub fn buffered(&self, conn: u64) -> usize {
        (self.buffered)(conn)
    }

    pub fn close(&self, conn: u64, code: u16) {
        (self.close)(conn, code)
    }

    /// A message to every PHP subscription of `channel` in this process.
    pub fn post(&self, channel: &str, message: &[u8]) {
        (self.post)(
            channel.as_ptr().cast(),
            channel.len(),
            message.as_ptr(),
            message.len(),
        )
    }

    /// Finishes an asynchronous call with its result, or with an error message.
    pub fn complete(&self, token: *mut Token, result: Result<&[u8], &str>) {
        match result {
            Ok(bytes) => (self.complete)(token, bytes.as_ptr(), bytes.len(), 0),
            Err(message) => (self.complete)(token, message.as_ptr(), message.len(), 1),
        }
    }

    /// The result of a synchronous call.
    pub fn reply(&self, out: *mut Reply, bytes: &[u8]) {
        (self.reply)(out, bytes.as_ptr(), bytes.len())
    }

    pub fn log(&self, level: i32, message: &str) {
        (self.log)(level, message.as_ptr().cast(), message.len())
    }
}
