//! Async databases for PHP: Postgres and Redis. The fiber waits for the result, the event loop
//! serves other requests in the meantime. Results go to PHP as JSON.
//!
//! Postgres behaves like PDO: parameters go as text, the server infers their type from the
//! query ('42' fits an int column, dates as strings). Postgres converts results itself with
//! json_agg, so timestamps, numeric, uuid and json arrive too.

use std::cell::RefCell;
use std::collections::HashMap;
use std::error::Error;
use std::rc::Rc;
use std::sync::Arc;
use std::sync::atomic::{AtomicUsize, Ordering};

use bytes::BytesMut;
use serde_json::Value;
use tokio_postgres::types::{Format, IsNull, ToSql, Type, to_sql_checked};
use tokio::sync::{OwnedSemaphorePermit, Semaphore};
use tokio_postgres::{Client, NoTls};

use crate::ops;
use crate::php::{OpResult, text};

type Failure = Box<dyn Error + Send + Sync>;

/// At most this many Postgres connections per worker and URL (--db-pool); anyone beyond that waits.
pub static POOL_SIZE: AtomicUsize = AtomicUsize::new(10);

thread_local! {
    /// Idle Postgres connections per URL.
    static IDLE: RefCell<HashMap<String, Vec<Client>>> = RefCell::new(HashMap::new());
    /// Permits per URL: one per connection currently in use.
    static PERMITS: RefCell<HashMap<String, Arc<Semaphore>>> = RefCell::new(HashMap::new());
    /// Open transactions: a connection nobody else gets until commit or rollback.
    static TRANSACTIONS: RefCell<HashMap<u64, Transaction>> = RefCell::new(HashMap::new());
    static REDIS: RefCell<HashMap<String, redis::aio::MultiplexedConnection>> = RefCell::new(HashMap::new());
}

struct Transaction {
    url: String,
    /// The request that began it; if it ends with exit(), tenma_rs_db_abandon discards it.
    owner: u64,
    client: Rc<RefCell<Slot>>,
}

/// Where a transaction's connection currently is.
enum Slot {
    Idle(Lease),
    /// A query is running and holds the connection.
    Busy,
    /// The running query was cancelled (Tenma\all, Task::cancel): its connection was closed with it,
    /// Postgres has discarded the transaction.
    Lost,
}

/// Holds a transaction's connection during a query. If the query is cancelled, the guard is dropped
/// with the connection and marks the transaction as lost, instead of leaving it occupied forever.
struct Busy {
    slot: Rc<RefCell<Slot>>,
    lease: Option<Lease>,
}

impl Busy {
    fn restore(mut self) {
        if let Some(lease) = self.lease.take() {
            *self.slot.borrow_mut() = Slot::Idle(lease);
        }
    }
}

impl Drop for Busy {
    fn drop(&mut self) {
        if self.lease.is_some() {
            *self.slot.borrow_mut() = Slot::Lost;
        }
    }
}

/// A connection plus its permit from the pool; release returns both.
struct Lease {
    client: Client,
    _permit: OwnedSemaphorePermit,
}

/// Forgets all connections of this worker, e.g. on restart.
pub fn clear() {
    IDLE.with_borrow_mut(HashMap::clear);
    PERMITS.with_borrow_mut(HashMap::clear);
    TRANSACTIONS.with_borrow_mut(HashMap::clear);
    REDIS.with_borrow_mut(HashMap::clear);
}

/// A parameter as text; the server decides the type, as with PDO.
#[derive(Debug)]
struct TextParam(Option<String>);

impl ToSql for TextParam {
    fn to_sql(&self, _: &Type, out: &mut BytesMut) -> Result<IsNull, Failure> {
        match &self.0 {
            Some(text) => {
                out.extend_from_slice(text.as_bytes());
                Ok(IsNull::No)
            }
            None => Ok(IsNull::Yes),
        }
    }

    fn accepts(_: &Type) -> bool {
        true
    }

    fn encode_format(&self, _: &Type) -> Format {
        Format::Text
    }

    to_sql_checked!();
}

fn text_params(params: &[Value]) -> Vec<TextParam> {
    params
        .iter()
        .map(|value| {
            TextParam(match value {
                Value::Null => None,
                Value::Bool(flag) => Some(if *flag { "1" } else { "0" }.into()),
                Value::String(text) => Some(text.clone()),
                other => Some(other.to_string()),
            })
        })
        .collect()
}

async fn connect(url: &str) -> Result<Lease, Failure> {
    let permits = PERMITS.with_borrow_mut(|permits| Arc::clone(permits.entry(url.to_owned()).or_insert_with(|| Arc::new(Semaphore::new(POOL_SIZE.load(Ordering::Relaxed))))));
    let permit = permits.acquire_owned().await?;
    let idle = IDLE.with_borrow_mut(|idle| idle.get_mut(url).and_then(|clients| std::iter::from_fn(|| clients.pop()).find(|client| !client.is_closed())));
    let client = match idle {
        Some(client) => client,
        None => {
            // ponytail: no TLS; postgres-native-tls once databases outside the own network come along
            let mut config: tokio_postgres::Config = url.parse()?;
            if config.get_connect_timeout().is_none() {
                config.connect_timeout(std::time::Duration::from_secs(10));
            }
            let (client, connection) = config.connect(NoTls).await?;
            tokio::task::spawn_local(connection);
            client
        }
    };
    Ok(Lease { client, _permit: permit })
}

/// Returns the connection, but cleaned first: DISCARD ALL forgets what the request left behind
/// (SET ROLE, set_config for row-level security, temporary tables, prepared statements). In an
/// open transaction DISCARD ALL fails; then the connection is discarded, which aborts the
/// transaction. Runs in the background, the response does not wait; the pool permit is held until then.
fn release(url: &str, lease: Lease) {
    if lease.client.is_closed() {
        return;
    }
    let url = url.to_owned();
    // Both moved by name: an async block captures only the fields it uses, and a permit left
    // behind would free its place while DISCARD ALL still runs, so the pool opens a connection more
    let Lease { client, _permit: permit } = lease;
    tokio::task::spawn_local(async move {
        if client.batch_execute("DISCARD ALL").await.is_ok() {
            IDLE.with_borrow_mut(|idle| idle.entry(url).or_default().push(client));
        }
        drop(permit);
    });
}

/// Runs a query: "query" returns the rows as a JSON array, "execute" the number of affected rows,
/// "script" runs several statements without parameters (simple query protocol).
async fn run(client: &Client, mode: &str, sql: &str, params: &[Value]) -> Result<String, Failure> {
    if mode == "script" {
        client.batch_execute(sql).await?;
        return Ok("null".to_owned());
    }
    let params = text_params(params);
    let refs: Vec<&(dyn ToSql + Sync)> = params.iter().map(|param| param as &(dyn ToSql + Sync)).collect();
    if mode == "execute" {
        let affected = client.execute(sql, &refs).await?;
        return Ok(affected.to_string());
    }
    let sql = sql.trim().trim_end_matches(';');
    // Newline before the parenthesis: otherwise a --comment at the end of the query swallows it.
    // MATERIALIZED: the rows come in the order the query returns them (ORDER BY)
    let wrapped = format!("WITH tenma_query AS MATERIALIZED ({sql}\n) SELECT coalesce(json_agg(tenma_query), '[]')::text FROM tenma_query");
    let row = client.query_one(&wrapped, &refs).await?;
    Ok(row.get(0))
}

async fn postgres(mode: String, target: String, sql: String, params: Vec<Value>) -> Result<String, Failure> {
    // Queries in a transaction use its connection
    if let Some(id) = target.strip_prefix("tx:").and_then(|id| id.parse::<u64>().ok()) {
        let Some((url, cell)) = TRANSACTIONS.with_borrow(|txs| txs.get(&id).map(|tx| (tx.url.clone(), Rc::clone(&tx.client)))) else {
            return Err("transaction has already ended".into());
        };
        let ending = mode == "commit" || mode == "rollback";
        let slot = std::mem::replace(&mut *cell.borrow_mut(), Slot::Busy);
        let lease = match slot {
            Slot::Idle(lease) => lease,
            Slot::Busy => return Err("a query is already running in this transaction".into()),
            Slot::Lost => {
                *cell.borrow_mut() = Slot::Lost;
                if ending {
                    TRANSACTIONS.with_borrow_mut(|txs| txs.remove(&id));
                }
                // A rollback has already happened; nothing else works any more
                return if mode == "rollback" { Ok("null".into()) } else { Err("the transaction was aborted".into()) };
            }
        };
        let busy = Busy { slot: Rc::clone(&cell), lease: Some(lease) };
        let client = &busy.lease.as_ref().expect("just set").client;
        let result = if ending {
            client.batch_execute(if mode == "commit" { "COMMIT" } else { "ROLLBACK" }).await.map(|()| "null".to_owned()).map_err(Failure::from)
        } else {
            run(client, &mode, &sql, &params).await
        };
        if ending {
            TRANSACTIONS.with_borrow_mut(|txs| txs.remove(&id));
            let mut busy = busy;
            if let Some(lease) = busy.lease.take() {
                release(&url, lease);
            }
        } else {
            busy.restore();
        }
        return result;
    }
    let lease = connect(&target).await?;
    if mode == "begin" {
        lease.client.batch_execute("BEGIN").await?;
        // PHP picks the ID: if it is cancelled while this finishes, it can still roll back
        let owner = params.first().and_then(Value::as_u64).unwrap_or(0);
        let id = params.get(1).and_then(Value::as_u64).unwrap_or_else(crate::php::next_id);
        TRANSACTIONS.with_borrow_mut(|txs| txs.insert(id, Transaction { url: target, owner, client: Rc::new(RefCell::new(Slot::Idle(lease))) }));
        return Ok(id.to_string());
    }
    let result = run(&lease.client, &mode, &sql, &params).await;
    release(&target, lease);
    result
}

async fn redis_command(url: String, args: Vec<Value>) -> Result<String, Failure> {
    let existing = REDIS.with_borrow(|connections| connections.get(&url).cloned());
    let mut connection = match existing {
        Some(connection) => connection,
        None => {
            let config = redis::AsyncConnectionConfig::new()
                .set_connection_timeout(Some(std::time::Duration::from_secs(10)))
                .set_response_timeout(Some(std::time::Duration::from_secs(30)));
            let connection = redis::Client::open(url.as_str())?.get_multiplexed_async_connection_with_config(&config).await?;
            REDIS.with_borrow_mut(|connections| connections.insert(url.clone(), connection.clone()));
            connection
        }
    };
    let mut command = redis::Cmd::new();
    for arg in &args {
        match arg {
            Value::String(text) => command.arg(text.as_bytes()),
            other => command.arg(other.to_string()),
        };
    }
    let value: redis::Value = match command.query_async(&mut connection).await {
        Ok(value) => value,
        Err(error) => {
            // After a Redis restart the connection would otherwise stay dead forever: reconnect next time
            REDIS.with_borrow_mut(|connections| connections.remove(&url));
            return Err(error.into());
        }
    };
    Ok(redis_json(value).to_string())
}

fn redis_json(value: redis::Value) -> Value {
    match value {
        redis::Value::Nil => Value::Null,
        redis::Value::Int(number) => number.into(),
        redis::Value::BulkString(bytes) => String::from_utf8_lossy(&bytes).into_owned().into(),
        redis::Value::SimpleString(text) => text.into(),
        redis::Value::Okay => "OK".into(),
        redis::Value::Array(items) | redis::Value::Set(items) => items.into_iter().map(redis_json).collect(),
        redis::Value::Boolean(flag) => flag.into(),
        redis::Value::Double(number) => number.into(),
        redis::Value::Map(pairs) => pairs.into_iter().map(|(key, value)| Value::Array(vec![redis_json(key), redis_json(value)])).collect(),
        other => format!("{other:?}").into(),
    }
}

/// Starts a database op. $op: "pg.query", "pg.execute", "pg.script", "pg.begin", "pg.commit", "pg.rollback" or
/// "redis"; $target: URL or "tx:<id>"; $params: JSON array. The result comes as JSON text.
#[unsafe(no_mangle)]
extern "C" fn tenma_rs_db(op: *const u8, op_len: usize, target: *const u8, target_len: usize, sql: *const u8, sql_len: usize, params: *const u8, params_len: usize) -> u64 {
    let (op, target, sql) = (text(op, op_len), text(target, target_len), text(sql, sql_len));
    let params: Vec<Value> = serde_json::from_str(&text(params, params_len)).unwrap_or_default();
    // COMMIT and ROLLBACK run to completion even if the caller is cancelled: cancelled midway,
    // it would be unclear whether the transaction holds, and its entry would stay forever
    let spawn = if matches!(op.as_str(), "pg.commit" | "pg.rollback") { ops::spawn_uncancellable } else { ops::spawn };
    spawn(async move {
        let result = match op.split_once('.') {
            Some(("pg", mode)) => postgres(mode.to_owned(), target, sql, params).await,
            _ if op == "redis" => redis_command(target, params).await,
            _ => Err(format!("unknown database op {op}").into()),
        };
        match result {
            Ok(json) => OpResult::Text(json.into_bytes()),
            Err(error) => OpResult::Error(describe(error.as_ref())),
        }
    })
}

/// Discards the open transactions of a request that ended without commit or rollback (exit()).
/// The connection is closed, Postgres aborts the transaction and releases its locks.
#[unsafe(no_mangle)]
extern "C" fn tenma_rs_db_abandon(rid: u64) {
    TRANSACTIONS.with_borrow_mut(|txs| txs.retain(|_, tx| tx.owner != rid));
}

/// The error message with all causes: otherwise tokio-postgres only says "db error".
fn describe(error: &(dyn Error + 'static)) -> String {
    let mut message = error.to_string();
    let mut source = error.source();
    while let Some(cause) = source {
        message = format!("{message}: {cause}");
        source = cause.source();
    }
    message
}
