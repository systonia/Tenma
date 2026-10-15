// The C layer between Rust and PHP. Only this file touches the Zend API: embed PHP,
// provide functions to PHP code, call handlers, convert PHP values into plain C values.
// Rust never sees a zval. Every call into PHP runs inside zend_try, so a fatal error
// (zend_bailout, a longjmp) never jumps through Rust code.

#include <sapi/embed/php_embed.h>
#include <Zend/zend_exceptions.h>
#include <Zend/zend_extensions.h>
#include <Zend/zend_fibers.h>
#include <Zend/zend_enum.h>
#include <Zend/zend_lazy_objects.h>
#include <Zend/zend_observer.h>
#include <Zend/zend_signal.h>
#include <main/rfc1867.h>
#include <main/php_ini_builder.h>
#include <Zend/zend_smart_str.h>
#include <ext/filter/php_filter.h>
#include <ext/session/php_session.h>
#include <ext/standard/basic_functions.h>
#include <ext/spl/spl_exceptions.h>
#include <main/fopen_wrappers.h>

ZEND_EXTERN_MODULE_GLOBALS(filter)
#include <signal.h>
#include <stdint.h>
#include <stdlib.h>
#include <string.h>

typedef struct {
    const char *ptr;
    size_t len;
} tenma_str;

typedef struct {
    tenma_str name;
    tenma_str value;
} tenma_header;

typedef struct {
    tenma_str method;
    tenma_str uri;
    tenma_str body;
    const tenma_header *headers;
    size_t header_count;
    /** Both ends of the connection, as text ("127.0.0.1") and port. */
    tenma_str remote_addr;
    uint16_t remote_port;
    tenma_str server_addr;
    uint16_t server_port;
} tenma_request;

/** Owned by Rust after tenma_handle; free with tenma_response_free. */
typedef struct {
    uint16_t status;
    char *body;
    size_t body_len;
    /** Header lines as with header(): "Name: Value\0Name: Value\0" … */
    char *headers;
    size_t headers_len;
} tenma_response;

/** Rust stores port and handler; returns 0 if the port is invalid. */
extern int tenma_rs_listen(zend_long port, uint32_t handler, const char *mounts, size_t mounts_len);
/** Hands Rust the response to request rid. Rust frees it with tenma_response_free. */
extern void tenma_rs_respond(uint64_t rid, tenma_response *response);
/** Async ops: Rust starts them and returns an op ID; Rust reports completion via tenma_complete_*. */
extern uint64_t tenma_rs_sleep(zend_long milliseconds);
/** Streaming responses and WebSockets, see src/streams.rs. */
extern int tenma_rs_stream_start(uint64_t rid, uint16_t status, const char *headers, size_t headers_len);
extern int64_t tenma_rs_stream_write(uint64_t rid, const char *chunk, size_t len);
extern void tenma_rs_stream_end(uint64_t rid, int finished);
extern int tenma_rs_ws_accept(uint64_t rid);
extern int tenma_rs_ws_send(uint64_t rid, const char *text, size_t len);
extern uint64_t tenma_rs_ws_receive(uint64_t rid);
extern void tenma_rs_ws_close(uint64_t rid, int finished);
/** Databases, see src/db.rs. */
extern void tenma_rs_db_abandon(uint64_t rid);
extern void tenma_rs_op_cancel(uint64_t op);
extern uint64_t tenma_rs_pubsub(const char *op, size_t op_len, uint64_t id, const char *channel, size_t channel_len,
    const char *message, size_t message_len);
/** File system, see src/fs.rs. */
extern uint64_t tenma_rs_fs(const char *op, size_t op_len, const char *path, size_t path_len,
    const char *second, size_t second_len, bool flag, const char *data, size_t data_len);
/** exit() outside a request: tenma exits with this status. */
extern void tenma_rs_exit(int status);
/** tenma --watch: a file PHP loaded, see tenma_report_included. */
extern void tenma_rs_included(const char *path, size_t len);
/** tenma --watch: the length of the path of the change behind this run, copied into buffer if it fits; SIZE_MAX for none. */
extern size_t tenma_rs_reloaded_by(char *buffer, size_t capacity);
/** tenma --inspect: a statement is about to run, see inspect_statement. */
extern void tenma_rs_statement(const char *file, size_t file_len, uint32_t line, uintptr_t frame, uintptr_t fiber);
/** tenma --inspect: a function is about to return, at its closing brace on `line`. */
extern void tenma_rs_return(const char *file, size_t file_len, uint32_t line, uintptr_t frame, uintptr_t fiber);
/** tenma --inspect: PHP compiled a function (or a file's top level, `main`) with statements on these lines. */
extern void tenma_rs_compiled(const char *file, size_t file_len, uint32_t start, uint32_t end, bool main, const uint32_t *lines, size_t count);
extern uint64_t tenma_rs_dev(const char *op, size_t op_len, const char *arg, size_t arg_len);
extern uint64_t tenma_rs_db(const char *op, size_t op_len, const char *target, size_t target_len,
    const char *sql, size_t sql_len, const char *params, size_t params_len);
extern uint64_t tenma_rs_fetch(const char *method, size_t method_len, const char *url, size_t url_len,
    const char *headers, size_t headers_len, const char *body, size_t body_len, uint64_t timeout_ms);

// Each worker thread has its own PHP interpreter, and so its own handlers
#define MAX_HANDLERS 64
static _Thread_local zval handlers[MAX_HANDLERS];
static _Thread_local uint32_t handler_count = 0;

/** The request PHP last answered with tenma_respond. */
static _Thread_local uint64_t responded_rid = 0;
/** exit() or die() ran in this thread since tenma_execute began (see tenma_exit). */
static _Thread_local int script_exited = 0;
/** A classic request is running (tenma_classic_begin); its shutdown functions and response are pending. */
static _Thread_local int classic_open = 0;
/** The shutdown functions from script startup, set aside during a classic request. */
static _Thread_local HashTable *outer_shutdown_functions = NULL;
/**
 * The output of the running classic request. Tenma collects it where PHP writes (ub_write),
 * not in an ob_start() buffer: frameworks like Symfony, Laravel or WordPress flush all output
 * buffers when sending, and the response would otherwise have ended up on the console.
 */
static _Thread_local smart_str classic_output = {0};
/** Where the embed SAPI writes when no classic request is running: stdout. */
static size_t (*embed_ub_write)(const char *str, size_t length) = NULL;

/** tenma --inspect, set before tenma_start. */
static int inspect_mode = 0;

/** tenma --inspect: output also goes to the editor's debug console; 1 for the error log. */
extern void tenma_rs_output(const char *text, size_t len, int log);

static size_t tenma_ub_write(const char *str, size_t length) {
    if (classic_open) {
        smart_str_appendl(&classic_output, str, length);
        return length;
    }
    if (inspect_mode) {
        tenma_rs_output(str, length, 0);
    }
    return embed_ub_write(str, length);
}

static void (*embed_log_message)(const char *message, int syslog_type) = NULL;

static void tenma_log_message(const char *message, int syslog_type) {
    tenma_rs_output(message, strlen(message), 1);
    embed_log_message(message, syslog_type);
}

/** ini values changed before the classic request: name → value. What the request changes is restored afterwards. */
static _Thread_local HashTable *ini_snapshot = NULL;

static char *resolve_path(zend_string *path);

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_listen, 0, 2, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, port, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, handler, IS_CALLABLE, 0)
    ZEND_ARG_TYPE_INFO(0, mounts, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

/**
 * tenma_listen(int $port, callable $handler, array $mounts = []): void. The handler receives
 * (array $request, int $rid) and responds via tenma_respond. $mounts: URL prefix → directory or
 * file; Rust serves whatever lies below it.
 */
PHP_FUNCTION(tenma_listen) {
    zend_long port;
    zval *handler;
    HashTable *mounts = NULL;
    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_LONG(port)
        Z_PARAM_ZVAL(handler)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_HT(mounts)
    ZEND_PARSE_PARAMETERS_END();

    if (!zend_is_callable(handler, 0, NULL)) {
        zend_argument_type_error(2, "must be a valid callback");
        RETURN_THROWS();
    }
    if (handler_count == MAX_HANDLERS) {
        zend_throw_error(NULL, "Too many servers");
        RETURN_THROWS();
    }
    // Prefix and path alternating, each terminated by a null byte. Paths as with PHP's file functions:
    // relative to the working directory, open_basedir applies
    smart_str packed = {0};
    if (mounts) {
        zend_string *prefix;
        zval *target;
        ZEND_HASH_FOREACH_STR_KEY_VAL(mounts, prefix, target) {
            if (!prefix || Z_TYPE_P(target) != IS_STRING || memchr(ZSTR_VAL(prefix), 0, ZSTR_LEN(prefix))
                || memchr(Z_STRVAL_P(target), 0, Z_STRLEN_P(target))) {
                smart_str_free(&packed);
                zend_argument_type_error(3, "must map URL prefixes to paths");
                RETURN_THROWS();
            }
            char *path = resolve_path(Z_STR_P(target));
            if (!path) {
                smart_str_free(&packed);
                RETURN_THROWS();
            }
            smart_str_append(&packed, prefix);
            smart_str_appendc(&packed, '\0');
            smart_str_appends(&packed, path);
            smart_str_appendc(&packed, '\0');
            efree(path);
        } ZEND_HASH_FOREACH_END();
    }
    int registered = tenma_rs_listen(port, handler_count, packed.s ? ZSTR_VAL(packed.s) : "", packed.s ? ZSTR_LEN(packed.s) : 0);
    smart_str_free(&packed);
    if (registered == 0) {
        zend_argument_value_error(1, "must be between 1 and 65535");
        RETURN_THROWS();
    }
    if (registered < 0) {
        zend_argument_value_error(3, "must map to existing directories or files");
        RETURN_THROWS();
    }
    ZVAL_COPY(&handlers[handler_count++], handler);
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_respond, 0, 2, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, rid, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, response, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_op_sleep, 0, 1, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, milliseconds, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_op_fetch, 0, 5, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, url, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, headers, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, body, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, timeout_ms, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_set_input, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, body, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_parse_multipart, 0, 2, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, content_type, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, body, IS_STRING, 0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(tenma_respond);
PHP_FUNCTION(tenma_op_sleep);
PHP_FUNCTION(tenma_op_fetch);
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_forget_session, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(tenma_set_input);
PHP_FUNCTION(tenma_parse_multipart);
PHP_FUNCTION(tenma_forget_session);

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_stream_start, 0, 3, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, rid, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, status, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, headers, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_rid_string_long, 0, 2, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, rid, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_rid_string_bool, 0, 2, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, rid, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_rid_void, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, rid, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_rid_finished, 0, 2, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, rid, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, finished, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_rid_bool, 0, 1, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, rid, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_rid_long, 0, 1, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, rid, IS_LONG, 0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(tenma_stream_start);
PHP_FUNCTION(tenma_stream_write);
PHP_FUNCTION(tenma_stream_end);
PHP_FUNCTION(tenma_ws_accept);
PHP_FUNCTION(tenma_ws_send);
PHP_FUNCTION(tenma_ws_receive);
PHP_FUNCTION(tenma_ws_close);

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_op_db, 0, 4, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, op, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, target, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, sql, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, params, IS_STRING, 0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(tenma_op_db);
PHP_FUNCTION(tenma_db_abandon);

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_parse_cookies, 0, 2, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO(0, header, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, global, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(tenma_parse_cookies);

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_op_cancel, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, op, IS_LONG, 0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(tenma_op_cancel);
PHP_FUNCTION(tenma_exit_process);
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_op_fs, 0, 5, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, op, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, path, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, second, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, flag, _IS_BOOL, 0)
    ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(tenma_op_fs);

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_op_dev, 0, 2, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, op, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, arg, IS_STRING, 0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(tenma_op_dev);
PHP_FUNCTION(tenma_debugger);

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_reloaded_by, 0, 0, IS_STRING, 1)
ZEND_END_ARG_INFO()

PHP_FUNCTION(tenma_reloaded_by);

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_pubsub, 0, 4, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, op, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, id, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, channel, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, message, IS_STRING, 0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(tenma_pubsub);

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_shutdown, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

PHP_FUNCTION(tenma_classic_begin);
PHP_FUNCTION(tenma_take_output);
ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_tenma_take_output, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry tenma_functions[] = {
    ZEND_FE(tenma_listen, arginfo_tenma_listen)
    ZEND_FE(tenma_respond, arginfo_tenma_respond)
    ZEND_FE(tenma_op_sleep, arginfo_tenma_op_sleep)
    ZEND_FE(tenma_op_fetch, arginfo_tenma_op_fetch)
    ZEND_FE(tenma_set_input, arginfo_tenma_set_input)
    ZEND_FE(tenma_parse_multipart, arginfo_tenma_parse_multipart)
    ZEND_FE(tenma_forget_session, arginfo_tenma_forget_session)
    ZEND_FE(tenma_stream_start, arginfo_tenma_stream_start)
    ZEND_FE(tenma_stream_write, arginfo_tenma_rid_string_long)
    ZEND_FE(tenma_stream_end, arginfo_tenma_rid_finished)
    ZEND_FE(tenma_ws_accept, arginfo_tenma_rid_bool)
    ZEND_FE(tenma_ws_send, arginfo_tenma_rid_string_bool)
    ZEND_FE(tenma_ws_receive, arginfo_tenma_rid_long)
    ZEND_FE(tenma_ws_close, arginfo_tenma_rid_finished)
    ZEND_FE(tenma_op_db, arginfo_tenma_op_db)
    ZEND_FE(tenma_db_abandon, arginfo_tenma_rid_void)
    ZEND_FE(tenma_parse_cookies, arginfo_tenma_parse_cookies)
    ZEND_FE(tenma_op_cancel, arginfo_tenma_op_cancel)
    ZEND_FE(tenma_exit_process, arginfo_tenma_shutdown)
    ZEND_FE(tenma_op_fs, arginfo_tenma_op_fs)
    ZEND_FE(tenma_op_dev, arginfo_tenma_op_dev)
    ZEND_FE(tenma_pubsub, arginfo_tenma_pubsub)
    ZEND_FE(tenma_debugger, arginfo_tenma_shutdown)
    ZEND_FE(tenma_reloaded_by, arginfo_tenma_reloaded_by)
    ZEND_FE(tenma_classic_begin, arginfo_tenma_shutdown)
    ZEND_FE(tenma_take_output, arginfo_tenma_take_output)
    ZEND_FE_END
};

static PHP_MINIT_FUNCTION(tenma);

static zend_module_entry tenma_module = {
    STANDARD_MODULE_HEADER,
    "tenma",
    tenma_functions,
    PHP_MINIT(tenma), NULL, NULL, NULL, NULL,
    /* From Cargo.toml (build.rs): phpversion('tenma') and phpinfo() show the binary's version */
    TENMA_VERSION,
    STANDARD_MODULE_PROPERTIES
};

/** Settings from the command line (-d), as with the PHP CLI; they win over any php.ini. */
static struct php_ini_builder ini_builder;
static int ini_builder_used = 0;

void tenma_define_ini(const char *entry) {
    if (!ini_builder_used) {
        php_ini_builder_init(&ini_builder);
        ini_builder_used = 1;
    }
    php_ini_builder_define(&ini_builder, entry);
}

/** Like server_name in nginx: $_SERVER['SERVER_NAME'] in classic mode, never from the Host header. */
static char *server_name = "localhost";

void tenma_set_server_name(const char *name) {
    server_name = strdup(name);
}

/** Like php -c: this php.ini (or this directory) instead of searching for one. */
void tenma_set_ini_path(const char *path) {
    php_embed_module.php_ini_path_override = strdup(path);
}

/**
 * exit() and die() record that they ran before PHP unwinds. Otherwise a script that ends at
 * startup with die("Usage: …") could not be told apart from one that ran to completion, and
 * tenma would still start the timers it had already scheduled.
 */
static zif_handler original_exit = NULL;

static ZEND_NAMED_FUNCTION(tenma_exit) {
    script_exited = 1;
    // exit("text") leaves the status as is; without a reset, an earlier exit(5) in the worker would count
    EG(exit_status) = 0;
    original_exit(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

static void wrap_exit(void) {
    const char *names[] = {"exit", "die"};
    for (int i = 0; i < 2; i++) {
        zend_function *function = zend_hash_str_find_ptr(CG(function_table), names[i], strlen(names[i]));
        if (function && function->type == ZEND_INTERNAL_FUNCTION) {
            original_exit = function->internal_function.handler;
            function->internal_function.handler = tenma_exit;
        }
    }
}

/** 1 if the script last run with tenma_execute ended with exit() or die(). */
int tenma_script_exited(void) {
    return script_exited;
}

void tenma_set_inspect(void) {
    inspect_mode = 1;
}

/** The frame of the statement the debugger stopped at, NULL while PHP runs. */
static _Thread_local zend_execute_data *paused = NULL;
/** Stopped as the paused frame returns: the line of its closing brace and its return value. */
static _Thread_local uint32_t paused_line = 0;
static _Thread_local zval *paused_return = NULL;

static void inspect_throw(zend_object *exception);
/** The debugger runs on this thread: tenma_inspect_thread ran (not on the dev thread). */
static _Thread_local int inspecting = 0;
/** The debugger evaluates code: what it calls must not stop the debugger again. */
static _Thread_local int evaluating = 0;
/**
 * Evaluated code failed fatally while stopped: PHP is unusable. Once the editor lets the code run on,
 * the bailout continues from where it stopped, into the zend_try around the call into PHP.
 */
static _Thread_local int evaluation_fatal = 0;

static void bail_after_fatal_evaluation(void) {
    if (evaluation_fatal) {
        evaluation_fatal = 0;
        zend_bailout();
    }
}
static void (*previous_throw_hook)(zend_object *exception) = NULL;

/**
 * Runs before every statement of code compiled with ZEND_COMPILE_EXTENDED_STMT (see
 * tenma_inspect_thread): Rust decides whether to stop there and blocks the thread while stopped.
 */
static void inspect_statement(zend_execute_data *frame) {
    if (evaluating || !frame->func || !ZEND_USER_CODE(frame->func->type)) {
        return;
    }
    zend_string *file = frame->func->op_array.filename;
    zend_execute_data *outer = paused;
    paused = frame;
    tenma_rs_statement(ZSTR_VAL(file), ZSTR_LEN(file), frame->opline->lineno, (uintptr_t) frame, (uintptr_t) EG(active_fiber));
    paused = outer;
    bail_after_fatal_evaluation();
}

/**
 * A function returns (return, the end of its code or an exception): a breakpoint on its closing
 * brace stops here, whichever way it leaves, with the return value to look at.
 */
static void inspect_return(zend_execute_data *frame, zval *value) {
    if (!inspecting || evaluating || !frame->func || !ZEND_USER_CODE(frame->func->type) || (frame->func->common.fn_flags & ZEND_ACC_GENERATOR)) {
        return;
    }
    zend_op_array *op_array = &frame->func->op_array;
    zend_execute_data *outer = paused;
    paused = frame;
    paused_line = op_array->line_end;
    paused_return = value;
    tenma_rs_return(ZSTR_VAL(op_array->filename), ZSTR_LEN(op_array->filename), op_array->line_end, (uintptr_t) frame, (uintptr_t) EG(active_fiber));
    paused = outer;
    paused_line = 0;
    paused_return = NULL;
    bail_after_fatal_evaluation();
}

/** Without this flag PHP moves a returned variable into the return value, leaving NULL behind. */
static void inspect_call(zend_execute_data *frame) {
    ZEND_ADD_CALL_FLAG(frame, ZEND_CALL_OBSERVED);
}

/** Functions and methods, not a file's top level, whose end is the end of the file. */
static zend_observer_fcall_handlers inspect_observe(zend_execute_data *frame) {
    if (!frame->func || !frame->func->common.function_name) {
        return (zend_observer_fcall_handlers) {NULL, NULL};
    }
    return (zend_observer_fcall_handlers) {inspect_call, inspect_return};
}

/**
 * Output buffers per fiber. PHP keeps one stack of ob_start() buffers per interpreter; with many
 * requests in one worker, a request that waits inside ob_start() (a template asking the database)
 * would leave its buffer open for the next request to write into. So a fiber that is switched
 * away from takes its buffers along, and gets them back when it runs again, as under FPM, where
 * every request has the stack to itself. A fiber starts without buffers, also the ones open where
 * it was started.
 */
typedef struct {
    zend_stack handlers;
    php_output_handler *active;
    php_output_handler *running;
} output_state;

/** The set-aside buffers by fiber context: (zend_ulong) context → output_state *. */
static _Thread_local HashTable *output_states = NULL;

static void output_switch(zend_fiber_context *from, zend_fiber_context *to) {
    bool open = zend_stack_count(&OG(handlers)) > 0;
    if (!open && (!output_states || zend_hash_num_elements(output_states) == 0)) {
        return;
    }
    if (!output_states) {
        output_states = pemalloc(sizeof(HashTable), 1);
        zend_hash_init(output_states, 8, NULL, NULL, 1);
    }
    if (open) {
        output_state *state = pemalloc(sizeof(output_state), 1);
        *state = (output_state) {OG(handlers), OG(active), OG(running)};
        zend_hash_index_update_ptr(output_states, (zend_ulong) (uintptr_t) from, state);
        zend_stack_init(&OG(handlers), sizeof(php_output_handler *));
        OG(active) = NULL;
        OG(running) = NULL;
    }
    output_state *state = zend_hash_index_find_ptr(output_states, (zend_ulong) (uintptr_t) to);
    if (state) {
        zend_stack_destroy(&OG(handlers));
        OG(handlers) = state->handlers;
        OG(active) = state->active;
        OG(running) = state->running;
        zend_hash_index_del(output_states, (zend_ulong) (uintptr_t) to);
        pefree(state, 1);
    }
}

/** A fiber that ended with buffers still open: they go with it, unsent, as at the end of a request. */
static void output_destroy(zend_fiber_context *context) {
    output_state *state = output_states ? zend_hash_index_find_ptr(output_states, (zend_ulong) (uintptr_t) context) : NULL;
    if (!state) {
        return;
    }
    php_output_handler **handler;
    while ((handler = zend_stack_top(&state->handlers))) {
        zend_stack_del_top(&state->handlers);
        php_output_handler_free(handler);
    }
    zend_stack_destroy(&state->handlers);
    zend_hash_index_del(output_states, (zend_ulong) (uintptr_t) context);
    pefree(state, 1);
}

/** Observers are registered while PHP starts its modules, before it knows which are in use. */
static PHP_MINIT_FUNCTION(tenma) {
    if (inspect_mode) {
        zend_observer_fcall_register(inspect_observe);
    }
    zend_observer_fiber_switch_register(output_switch);
    zend_observer_fiber_destroy_register(output_destroy);
    return SUCCESS;
}

/** The debugger moves a breakpoint to the next line where a statement of the same function starts. */
static void inspect_compiled(zend_op_array *op_array) {
    if (!inspecting || !op_array->filename) {
        return;
    }
    uint32_t *lines = malloc((op_array->last + 1) * sizeof(*lines));
    size_t count = 0;
    for (uint32_t i = 0; i < op_array->last; i++) {
        if (op_array->opcodes[i].opcode == ZEND_EXT_STMT) {
            lines[count++] = op_array->opcodes[i].lineno;
        }
    }
    tenma_rs_compiled(ZSTR_VAL(op_array->filename), ZSTR_LEN(op_array->filename), op_array->line_start, op_array->line_end,
        op_array->function_name == NULL, lines, count);
    free(lines);
}

static zend_extension inspector = {
    .name = "tenma inspector",
    .version = PHP_VERSION,
    .op_array_handler = inspect_compiled,
    .statement_handler = inspect_statement,
};

static int tenma_startup(sapi_module_struct *sapi) {
    // php_embed_init has just set its fixed settings; the -d entries go after them
    if (ini_builder_used) {
        php_ini_builder_prepend(&ini_builder, sapi->ini_entries, strlen(sapi->ini_entries));
        sapi->ini_entries = php_ini_builder_finish(&ini_builder);
    }
    if (php_module_startup(sapi, &tenma_module) == FAILURE) {
        return FAILURE;
    }
    if (inspect_mode) {
        // Built in, not loaded from a .so: PHP calls its statement_handler all the same
        zend_register_extension(&inspector, NULL);
        previous_throw_hook = zend_throw_exception_hook;
        zend_throw_exception_hook = inspect_throw;
    }
    return SUCCESS;
}

/** Starts PHP with the tenma module. Afterwards exactly one PHP request runs, until tenma_stop. */
/**
 * Signals belong to Rust: on every request startup PHP installs its own handlers for SIGTERM, SIGINT
 * and co., which pass on to the default action and would kill tenma at once. Remove them before
 * Rust installs its own handlers.
 */
static void release_signals(void) {
    SIGG(reset) = 0;
    const int signals[] = {SIGHUP, SIGINT, SIGQUIT, SIGTERM, SIGUSR1, SIGUSR2};
    for (size_t i = 0; i < sizeof(signals) / sizeof(*signals); i++) {
        signal(signals[i], SIG_DFL);
    }
}

/** The body PHP's multipart parser reads via read_post; only set during tenma_parse_multipart (and reset by tenma_thread_start). */
static _Thread_local const char *post_data = NULL;
static _Thread_local size_t post_len = 0, post_pos = 0;

static size_t tenma_read_post(char *buffer, size_t count) {
    size_t left = post_data ? post_len - post_pos : 0;
    size_t n = count < left ? count : left;
    memcpy(buffer, post_data + post_pos, n);
    post_pos += n;
    return n;
}

/** tenma --watch, set before tenma_start. */
static int watch_mode = 0;

void tenma_set_watch(void) {
    watch_mode = 1;
}

#define INI_DEFAULT(name, value) \
    ZVAL_NEW_STR(&tmp, zend_string_init(value, sizeof(value) - 1, 1)); \
    zend_hash_str_update(configuration_hash, name, sizeof(name) - 1, &tmp);

/**
 * Defaults that a php.ini or -d can override. Tenma limits the size of bodies and uploads itself
 * (--max-body). Errors go to the log, which is stderr for tenma, never into a response:
 * in PHP, display_errors=stderr only applies to the cli, cgi and phpdbg SAPIs.
 */
static void tenma_ini_defaults(HashTable *configuration_hash) {
    zval tmp;
    INI_DEFAULT("post_max_size", "0");
    INI_DEFAULT("upload_max_filesize", "0");
    INI_DEFAULT("display_errors", "0");
    INI_DEFAULT("log_errors", "1");
    if (watch_mode) {
        // Opcache checks timestamps only every 2 s by default and would load the old code right after saving
        INI_DEFAULT("opcache.revalidate_freq", "0");
        // Not a PHP setting: get_cfg_var('tenma.watch') tells the stdlib (Tenma::diagnostics) that it runs under --watch
        INI_DEFAULT("tenma.watch", "1");
    }
    if (inspect_mode) {
        // The JIT runs code without the statement opcodes the debugger stops at
        INI_DEFAULT("opcache.enable", "0");
    }
}

int tenma_start(void) {
    // Like console.log, echo reaches the console at once, even when stdout is a pipe
    setvbuf(stdout, NULL, _IOLBF, 0);
    php_embed_module.startup = tenma_startup;
    php_embed_module.read_post = tenma_read_post;
    embed_ub_write = php_embed_module.ub_write;
    php_embed_module.ub_write = tenma_ub_write;
    php_embed_module.ini_defaults = tenma_ini_defaults;
    if (inspect_mode) {
        embed_log_message = php_embed_module.log_message;
        php_embed_module.log_message = tenma_log_message;
    }
    php_embed_module.name = "tenma";
    php_embed_module.pretty_name = "tenma";
    if (php_embed_init(0, NULL) != SUCCESS) {
        return -1;
    }
    release_signals();
    wrap_exit();
    return 0;
}

void tenma_stop(void) {
    php_embed_shutdown();
}

/**
 * STDIN, STDOUT and STDERR as in the PHP CLI: constants holding streams on the process's file
 * descriptors. Outside the CLI they are dup() copies; PHP closes them with the interpreter, the
 * process's own stay open. (The CLI keeps them open because it uses the originals.)
 */
static void register_std_streams(void) {
    const char *names[] = {"STDIN", "STDOUT", "STDERR"};
    const char *paths[] = {"php://stdin", "php://stdout", "php://stderr"};
    const char *modes[] = {"rb", "wb", "wb"};
    for (int i = 0; i < 3; i++) {
        php_stream *stream = php_stream_open_wrapper_ex(paths[i], modes[i], 0, NULL, NULL);
        if (!stream) {
            continue;
        }
        zend_constant constant;
        php_stream_to_zval(stream, &constant.value);
        Z_CONSTANT_FLAGS(constant.value) = 0;
        constant.name = zend_string_init_interned(names[i], strlen(names[i]), 0);
        zend_register_constant(&constant);
    }
}

/**
 * Turns the calling thread into a worker with its own PHP interpreter (ZTS): its own globals,
 * its own request as after php_embed_init. tenma_start must have run in the main thread first.
 */
int tenma_thread_start(void) {
    (void) ts_resource(0);
    // A fatal error inside tenma_parse_multipart skips its reset: the body it points at belonged to
    // the old interpreter, and php://input of the next request would read it
    post_data = NULL;
    post_len = post_pos = 0;
    SIGG(reset) = 0;
    SG(options) |= SAPI_OPTION_NO_CHDIR;
    if (php_request_startup() == FAILURE) {
        ts_free_thread();
        return -1;
    }
    SG(headers_sent) = 1;
    SG(request_info).no_headers = 1;
    php_register_variable("PHP_SELF", "-", NULL);
    register_std_streams();
    return 0;
}

void tenma_thread_stop(void) {
    classic_open = 0;
    memset(&classic_output, 0, sizeof(classic_output));
    ini_snapshot = NULL;
    outer_shutdown_functions = NULL;
    // The handlers may own the app (Tenma::serve(8080, new App())): its destructor is user code, and a
    // bailout there without zend_try would exit the whole process instead of ending this interpreter
    zend_try {
        for (uint32_t i = 0; i < handler_count; i++) {
            zval_ptr_dtor(&handlers[i]);
        }
    } zend_end_try();
    handler_count = 0;
    php_request_shutdown(NULL);
    if (output_states) {
        // What fibers still held went with the request's memory; only the records are left
        output_state *state;
        ZEND_HASH_FOREACH_PTR(output_states, state) {
            pefree(state, 1);
        } ZEND_HASH_FOREACH_END();
        zend_hash_destroy(output_states);
        pefree(output_states, 1);
        output_states = NULL;
    }
    ts_free_thread();
}

/**
 * tenma --watch: reports every file PHP loaded from position `from` on to Rust and returns the
 * new count. EG(included_files) only grows, and PHP adds a file before compiling it, so a file
 * with a syntax error is watched too.
 */
size_t tenma_report_included(size_t from) {
    size_t count = zend_hash_num_elements(&EG(included_files));
    if (count <= from) {
        return count;
    }
    size_t index = 0;
    zend_string *path;
    ZEND_HASH_FOREACH_STR_KEY(&EG(included_files), path) {
        if (index++ >= from && path) {
            tenma_rs_included(ZSTR_VAL(path), ZSTR_LEN(path));
        }
    } ZEND_HASH_FOREACH_END();
    return count;
}

/**
 * tenma --inspect: code this thread compiles from now on stops the debugger before each statement.
 * Not eval()'d code like the stdlib: PHP compiles that without the statement opcodes.
 */
void tenma_inspect_thread(void) {
    CG(compiler_options) |= ZEND_COMPILE_EXTENDED_STMT;
    inspecting = 1;
    // A fatal error in a destructor during tenma_inspect_resume left it set
    evaluating = 0;
}

/**
 * DAP threads are the fibers: thread 1 is the code outside fibers, a fiber is its object handle
 * plus 1. The fiber with this ID, NULL for thread 1 or if there is none.
 */
static zend_fiber *fiber_of(uint32_t thread) {
    uint32_t handle = thread - 1;
    if (thread < 2 || handle >= EG(objects_store).top) {
        return NULL;
    }
    zend_object *object = EG(objects_store).object_buckets[handle];
    return IS_OBJ_VALID(object) && object->ce == zend_ce_fiber ? (zend_fiber *) object : NULL;
}

static uint32_t thread_of(zend_fiber *fiber) {
    return fiber ? fiber->std.handle + 1 : 1;
}

/** The thread the debugger stopped in. */
uint32_t tenma_inspect_thread_id(void) {
    return thread_of(EG(active_fiber));
}

/**
 * The innermost frame of a thread and its first: the stopped one from the statement it stopped at,
 * a waiting fiber from where it suspended. Below a fiber's first frame lies whoever resumed it,
 * which changes from resume to resume. 0 if the thread has no frames to show.
 */
static int thread_frames(uint32_t thread, zend_execute_data **top, zend_execute_data **bottom) {
    zend_fiber *fiber = fiber_of(thread);
    if (fiber == EG(active_fiber) && (fiber || thread == 1)) {
        *top = paused;
    } else if (fiber && fiber->context.status == ZEND_FIBER_STATUS_SUSPENDED) {
        *top = fiber->execute_data;
    } else {
        return 0;
    }
    *bottom = fiber ? fiber->stack_bottom : NULL;
    return 1;
}

#define FOREACH_FRAME(top, bottom, frame) \
    for (zend_execute_data *frame = (top); frame; frame = frame == (bottom) ? NULL : frame->prev_execute_data)

/** Frames shown in the editor: PHP code, not internal functions and not the stdlib. */
static int shown_frame(zend_execute_data *frame) {
    return frame->func && ZEND_USER_CODE(frame->func->type) && frame->opline
        && strncmp(ZSTR_VAL(frame->func->op_array.filename), "tenma:", 6) != 0;
}

/** Copies packed fields into malloc() memory, which Rust can free on its own. */
static char *malloc_copy(smart_str *out, size_t *len) {
    zend_string *fields = smart_str_extract(out);
    *len = ZSTR_LEN(fields);
    char *copy = malloc(*len + 1);
    memcpy(copy, ZSTR_VAL(fields), *len);
    zend_string_release(fields);
    return copy;
}

/** A field ends with a null byte: one inside (an array key, a string) is shown as \0. */
static void add_field(smart_str *out, const char *text, size_t len) {
    for (const char *nul; (nul = memchr(text, 0, len)); len -= nul - text + 1, text = nul + 1) {
        smart_str_appendl(out, text, nul - text);
        smart_str_appends(out, "\\0");
    }
    smart_str_appendl(out, text, len);
    smart_str_appendc(out, 0);
}

/** How deep the stopped statement is in its fiber: a step over or out compares this. */
uint32_t tenma_inspect_depth(void) {
    uint32_t depth = 0;
    FOREACH_FRAME(paused, EG(active_fiber) ? EG(active_fiber)->stack_bottom : NULL, frame) {
        depth++;
    }
    return depth;
}

/**
 * The threads as "id\0name\0" each, the stopped one first: the tasks of Tenma's scheduler (requests,
 * timers, Tenma::async()) under their label, waiting ones marked. Rust frees it with free().
 */
char *tenma_inspect_threads(size_t *len) {
    smart_str out = {0};
    zend_fiber *stopped = EG(active_fiber);
    smart_str_append_unsigned(&out, thread_of(stopped));
    smart_str_appendc(&out, 0);
    zend_class_entry *scheduler = zend_hash_str_find_ptr(EG(class_table), ZEND_STRL("tenma\\internal\\scheduler"));
    zval *tasks = scheduler ? zend_read_static_property(scheduler, ZEND_STRL("taskOfFiber"), 1) : NULL;
    zval *stopped_task = tasks && Z_TYPE_P(tasks) == IS_ARRAY && stopped ? zend_hash_index_find(Z_ARRVAL_P(tasks), stopped->std.handle) : NULL;
    zval label;
    if (stopped_task) {
        zval *name = zend_read_property(Z_OBJCE_P(stopped_task), Z_OBJ_P(stopped_task), ZEND_STRL("label"), 1, &label);
        add_field(&out, Z_TYPE_P(name) == IS_STRING ? Z_STRVAL_P(name) : "Task", Z_TYPE_P(name) == IS_STRING ? Z_STRLEN_P(name) : 4);
    } else {
        smart_str_appends(&out, stopped ? "Fiber" : "main");
        smart_str_appendc(&out, 0);
    }
    if (tasks && Z_TYPE_P(tasks) == IS_ARRAY) {
        zend_ulong handle;
        zval *task;
        ZEND_HASH_FOREACH_NUM_KEY_VAL(Z_ARRVAL_P(tasks), handle, task) {
            zend_fiber *fiber = fiber_of(handle + 1);
            if (!fiber || fiber == stopped || Z_TYPE_P(task) != IS_OBJECT) {
                continue;
            }
            smart_str_append_unsigned(&out, handle + 1);
            smart_str_appendc(&out, 0);
            zval *name = zend_read_property(Z_OBJCE_P(task), Z_OBJ_P(task), ZEND_STRL("label"), 1, &label);
            if (Z_TYPE_P(name) == IS_STRING) {
                smart_str_append(&out, Z_STR_P(name));
            }
            if (fiber->context.status == ZEND_FIBER_STATUS_SUSPENDED) {
                smart_str_appends(&out, " (waiting)");
            }
            smart_str_appendc(&out, 0);
        } ZEND_HASH_FOREACH_END();
    }
    return malloc_copy(&out, len);
}

/** The shown frames of a thread as "file\0line\0function\0" each; Rust frees it with free(). */
char *tenma_inspect_frames(uint32_t thread, size_t *len) {
    smart_str out = {0};
    zend_execute_data *top, *bottom;
    if (!thread_frames(thread, &top, &bottom)) {
        return malloc_copy(&out, len);
    }
    FOREACH_FRAME(top, bottom, frame) {
        if (!shown_frame(frame)) {
            continue;
        }
        zend_function *function = frame->func;
        add_field(&out, ZSTR_VAL(function->op_array.filename), ZSTR_LEN(function->op_array.filename));
        smart_str_append_unsigned(&out, frame == paused && paused_line ? paused_line : frame->opline->lineno);
        smart_str_appendc(&out, 0);
        if (!function->common.function_name) {
            smart_str_appends(&out, "{main}");
        } else {
            if (function->common.scope) {
                smart_str_append(&out, function->common.scope->name);
                smart_str_appends(&out, "::");
            }
            smart_str_append(&out, function->common.function_name);
        }
        smart_str_appendc(&out, 0);
    }
    return malloc_copy(&out, len);
}

/** An object's properties without running PHP code (no __debugInfo, no lazy initializer); NULL if not. */
static HashTable *properties_of(zend_object *object) {
    return zend_object_is_lazy(object) ? NULL : object->handlers->get_properties(object);
}

/**
 * An array or object the editor may expand while stopped; its reference to one is the position in
 * expandable[] plus 1. Where it lies: `parent` and `key` or `index`; `table` and `key` for a
 * variable in a symbol table (its buckets move when evaluated code adds variables); or `value` for
 * a local (a slot that stays put while stopped). Elements are looked up again on each use: evaluated
 * code may have changed or freed what was there. Changing elements copies shared arrays on the way,
 * as PHP would.
 */
struct expandable {
    zval *value;
    uint32_t parent;
    zend_string *key;
    zend_ulong index;
    HashTable *table;
};

/**
 * Where a variable lies: in the array or object `parent` refers to, under `key` or `index`; in the
 * symbol table `table` under `key`; NULL for a local.
 */
struct place {
    uint32_t parent;
    zend_string *key;
    zend_ulong index;
    HashTable *table;
};

static _Thread_local struct expandable *expandable = NULL;
static _Thread_local uint32_t expandable_count = 0, expandable_size = 0;
/** Results of evaluated code, kept until the worker runs on: the editor may expand them. */
static _Thread_local zval **evaluated = NULL;
static _Thread_local uint32_t evaluated_count = 0;

static uint32_t expandable_reference(zval *value, const struct place *place) {
    if (expandable_count == expandable_size) {
        expandable_size = expandable_size ? expandable_size * 2 : 64;
        expandable = realloc(expandable, expandable_size * sizeof(*expandable));
    }
    struct expandable *entry = &expandable[expandable_count++];
    *entry = (struct expandable) {value, place ? place->parent : 0, place && place->key ? zend_string_copy(place->key) : NULL, place ? place->index : 0, place ? place->table : NULL};
    return expandable_count;
}

/**
 * The storage of an expandable value as it is now; NULL if it is gone. `write`: ready to be changed,
 * every shared array on its way from the variable is copied first (copy on write), so other copies
 * keep their value.
 */
static zval *lookup(uint32_t reference, bool write) {
    if (reference == 0 || reference > expandable_count) {
        return NULL;
    }
    struct expandable *entry = &expandable[reference - 1];
    if (entry->table) {
        zval *value = zend_hash_find(entry->table, entry->key);
        if (value) {
            ZVAL_DEINDIRECT(value);
            ZVAL_DEREF(value);
        }
        return value;
    }
    if (!entry->parent) {
        zval *value = entry->value;
        ZVAL_DEREF(value);
        return value;
    }
    zval *container = lookup(entry->parent, write);
    if (!container) {
        return NULL;
    }
    ZVAL_DEREF(container);
    zval *slot = NULL;
    if (Z_TYPE_P(container) == IS_ARRAY) {
        if (write) {
            SEPARATE_ARRAY(container);
        }
        slot = entry->key ? zend_hash_find(Z_ARRVAL_P(container), entry->key) : zend_hash_index_find(Z_ARRVAL_P(container), entry->index);
    } else if (Z_TYPE_P(container) == IS_OBJECT) {
        HashTable *properties = properties_of(Z_OBJ_P(container));
        slot = !properties ? NULL : entry->key ? zend_hash_find(properties, entry->key) : zend_hash_index_find(properties, entry->index);
    }
    if (slot) {
        ZVAL_DEINDIRECT(slot);
        ZVAL_DEREF(slot);
    }
    return slot;
}

/** The worker runs on: the references to arrays and objects lose their meaning. */
void tenma_inspect_resume(void) {
    for (uint32_t i = 0; i < expandable_count; i++) {
        if (expandable[i].key) {
            zend_string_release(expandable[i].key);
        }
    }
    free(expandable);
    expandable = NULL;
    expandable_count = expandable_size = 0;
    // Releasing them may run destructors: those must not stop the debugger while it resumes
    evaluating = 1;
    zend_fiber_switch_block();
    for (uint32_t i = 0; i < evaluated_count; i++) {
        zval_ptr_dtor(evaluated[i]);
        free(evaluated[i]);
    }
    zend_fiber_switch_unblock();
    evaluating = 0;
    free(evaluated);
    evaluated = NULL;
    evaluated_count = 0;
}


/** A value as the editor shows it: scalars in full (long strings cut), arrays and objects briefly. */
static void describe(smart_str *out, zval *value) {
    switch (Z_TYPE_P(value)) {
    case IS_ARRAY:
        smart_str_append_printf(out, "array(%u)", zend_hash_num_elements(Z_ARRVAL_P(value)));
        break;
    case IS_OBJECT:
        smart_str_append(out, Z_OBJCE_P(value)->name);
        if (Z_OBJCE_P(value)->ce_flags & ZEND_ACC_ENUM) {
            smart_str_appends(out, "::");
            smart_str_append(out, Z_STR_P(zend_enum_fetch_case_name(Z_OBJ_P(value))));
        }
        break;
    case IS_RESOURCE:
        smart_str_append_printf(out, "resource(%d)", Z_RES_HANDLE_P(value));
        break;
    default:
        smart_str_append_scalar(out, value, 1000);
    }
}

/** A variable as "name\0value\0type\0reference\0"; the reference is 0 for values without children. */
static void add_variable(smart_str *out, const char *prefix, const char *name, size_t name_len, zval *value, const struct place *place) {
    ZVAL_DEINDIRECT(value);
    // A variable's slot, not the reference it may hold: evaluated code may free that reference
    zval *slot = value;
    ZVAL_DEREF(value);
    if (Z_TYPE_P(value) == IS_UNDEF) {
        return;
    }
    smart_str_appends(out, prefix);
    add_field(out, name, name_len);
    describe(out, value);
    smart_str_appendc(out, 0);
    smart_str_appends(out, zend_zval_type_name(value));
    smart_str_appendc(out, 0);
    int children = Z_TYPE_P(value) == IS_ARRAY ? zend_hash_num_elements(Z_ARRVAL_P(value)) > 0
        : Z_TYPE_P(value) == IS_OBJECT && !(Z_OBJCE_P(value)->ce_flags & ZEND_ACC_ENUM);
    smart_str_append_unsigned(out, children ? expandable_reference(slot, place) : 0);
    smart_str_appendc(out, 0);
}

/** The local variables of a thread's shown frame `index`, as add_variable packs them. */
char *tenma_inspect_locals(uint32_t thread, uint32_t index, size_t *len) {
    smart_str out = {0};
    zend_execute_data *top, *bottom;
    if (!thread_frames(thread, &top, &bottom)) {
        return malloc_copy(&out, len);
    }
    FOREACH_FRAME(top, bottom, frame) {
        if (!shown_frame(frame) || index-- > 0) {
            continue;
        }
        if (frame == paused && paused_return) {
            add_variable(&out, "", ZEND_STRL("return value"), paused_return, NULL);
        }
        if (Z_TYPE(frame->This) == IS_OBJECT) {
            add_variable(&out, "$", ZEND_STRL("this"), &frame->This, NULL);
        }
        if (ZEND_CALL_INFO(frame) & ZEND_CALL_HAS_SYMBOL_TABLE) {
            // The script's top level: also variables created at runtime ($$name, extract()), without superglobals
            zend_string *name;
            zval *value;
            ZEND_HASH_FOREACH_STR_KEY_VAL(frame->symbol_table, name, value) {
                if (name && !zend_is_auto_global(name)) {
                    struct place place = {0, name, 0, frame->symbol_table};
                    add_variable(&out, "$", ZSTR_VAL(name), ZSTR_LEN(name), value, &place);
                }
            } ZEND_HASH_FOREACH_END();
        } else {
            zend_op_array *op_array = &frame->func->op_array;
            for (int i = 0; i < op_array->last_var; i++) {
                add_variable(&out, "$", ZSTR_VAL(op_array->vars[i]), ZSTR_LEN(op_array->vars[i]), ZEND_CALL_VAR_NUM(frame, i), NULL);
            }
        }
        break;
    }
    return malloc_copy(&out, len);
}

/** The elements of an array or the properties of an object the editor expands, as add_variable packs them. */
char *tenma_inspect_children(uint32_t reference, size_t *len) {
    smart_str out = {0};
    zval *value = lookup(reference, false);
    HashTable *children = !value ? NULL : Z_TYPE_P(value) == IS_ARRAY ? Z_ARRVAL_P(value) : Z_TYPE_P(value) == IS_OBJECT ? properties_of(Z_OBJ_P(value)) : NULL;
    if (!children) {
        return malloc_copy(&out, len);
    }
    // ponytail: the first 1000 only; DAP paging (start, count) if larger ones matter
    uint32_t shown = 0;
    zend_ulong index;
    zend_string *key;
    zval *child;
    ZEND_HASH_FOREACH_KEY_VAL(children, index, key, child) {
        if (shown++ == 1000) {
            break;
        }
        struct place place = {reference, key, index};
        if (!key) {
            char number[24];
            add_variable(&out, "", number, snprintf(number, sizeof(number), ZEND_ULONG_FMT, index), child, &place);
        } else if (Z_TYPE_P(value) == IS_OBJECT) {
            // Private and protected properties are stored as "\0Class\0name" and "\0*\0name"
            const char *class_name, *property;
            size_t property_len;
            zend_unmangle_property_name_ex(key, &class_name, &property, &property_len);
            add_variable(&out, "", property, property_len, child, &place);
        } else {
            add_variable(&out, "", ZSTR_VAL(key), ZSTR_LEN(key), child, &place);
        }
    } ZEND_HASH_FOREACH_END();
    return malloc_copy(&out, len);
}

/** The message of an exception, NULL if it has none. */
static zend_string *exception_message(zend_object *exception) {
    zval value;
    zval *message = zend_read_property(zend_get_exception_base(exception), exception, ZEND_STRL("message"), 1, &value);
    return Z_TYPE_P(message) == IS_STRING ? Z_STR_P(message) : NULL;
}

/**
 * Running code while stopped, in a frame: eval() uses the variables of EG(current_execute_data).
 * Stopped at a throw, the exception is pending and waits until the code ran; a throw and
 * zend_clear_exception() move the frames' opline, which the VM reads on after the stop.
 */
struct in_frame {
    zend_execute_data *current, *target;
    const zend_op *current_opline, *target_opline, *before_exception;
    zend_object *pending;
};

static void enter_frame(struct in_frame *run, zend_execute_data *target) {
    *run = (struct in_frame) {EG(current_execute_data), target, EG(current_execute_data)->opline, target->opline, EG(opline_before_exception), EG(exception)};
    EG(exception) = NULL;
    EG(current_execute_data) = target;
    evaluating = 1;
    // A fiber switch (Tenma::sleep() in the code) would leave the stopped statement mid-way
    zend_fiber_switch_block();
}

static void leave_frame(struct in_frame *run) {
    zend_fiber_switch_unblock();
    evaluating = 0;
    EG(current_execute_data) = run->current;
    run->current->opline = run->current_opline;
    run->target->opline = run->target_opline;
    EG(opline_before_exception) = run->before_exception;
    EG(exception) = run->pending;
}

/** Packs "error\0message\0" if the code threw (or `failure`), and clears the exception. */
static int failed(smart_str *out, bool failure) {
    if (!EG(exception) && !failure) {
        return 0;
    }
    smart_str_appends(out, "error");
    smart_str_appendc(out, 0);
    if (EG(exception)) {
        zend_string *message = exception_message(EG(exception));
        smart_str_append(out, EG(exception)->ce->name);
        if (message && ZSTR_LEN(message)) {
            smart_str_appends(out, ": ");
            smart_str_append(out, message);
        }
        zend_clear_exception();
    } else {
        smart_str_appends(out, "could not evaluate");
    }
    smart_str_appendc(out, 0);
    return 1;
}

/** Evaluates code in the entered frame into `result` (NULL without a result); otherwise packs the error. */
static int evaluate_code(smart_str *out, const char *code, size_t code_len, bool statements, zval *result) {
    ZVAL_NULL(result);
    zend_result compiled = FAILURE;
    bool fatal = false;
    zend_try {
        compiled = zend_eval_stringl(code, code_len, statements ? NULL : result, "debugger");
    } zend_catch {
        fatal = true;
    } zend_end_try();
    if (fatal) {
        evaluation_fatal = 1;
        smart_str_appends(out, "fatal");
        smart_str_appendc(out, 0);
        if (PG(last_error_message)) {
            smart_str_append(out, PG(last_error_message));
        }
        smart_str_appendc(out, 0);
        return -1;
    }
    if (failed(out, compiled == FAILURE)) {
        zval_ptr_dtor(result);
        return -1;
    }
    if (Z_TYPE_P(result) == IS_UNDEF) {
        ZVAL_NULL(result);
    }
    return 0;
}

/** Packs "ok\0" and a value as add_variable does, then "truthy\0text\0"; keeps a copy to expand. */
static void pack_result(smart_str *out, zval *value) {
    smart_str_appends(out, "ok");
    smart_str_appendc(out, 0);
    // Kept where it does not move: expandable[] may point at it
    zval *kept = malloc(sizeof(zval));
    ZVAL_COPY_DEREF(kept, value);
    evaluated = realloc(evaluated, ++evaluated_count * sizeof(*evaluated));
    evaluated[evaluated_count - 1] = kept;
    add_variable(out, "", "", 0, kept, NULL);
    smart_str_appendc(out, zend_is_true(kept) ? '1' : '0');
    smart_str_appendc(out, 0);
    if (Z_TYPE_P(kept) == IS_STRING) {
        add_field(out, Z_STRVAL_P(kept), Z_STRLEN_P(kept));
    } else {
        describe(out, kept);
        smart_str_appendc(out, 0);
    }
}

static zend_execute_data *shown_frame_at(uint32_t index) {
    FOREACH_FRAME(paused, EG(active_fiber) ? EG(active_fiber)->stack_bottom : NULL, frame) {
        if (shown_frame(frame) && index-- == 0) {
            return frame;
        }
    }
    return NULL;
}

static char *no_frame(smart_str *out, size_t *len) {
    smart_str_appends(out, "error");
    smart_str_appendc(out, 0);
    smart_str_appends(out, "not available in the stopped request");
    smart_str_appendc(out, 0);
    return malloc_copy(out, len);
}

/**
 * Evaluates `code` in shown frame `index` of the stopped thread, with its variables and $this, like
 * eval() there: an expression, or with `statements` code without a result. Packs
 * "status\0name\0value\0type\0reference\0truthy\0text\0" (see pack_result), or "error\0message\0"
 * or "fatal\0message\0". Rust frees it with free().
 */
char *tenma_inspect_evaluate(uint32_t index, const char *code, size_t code_len, bool statements, size_t *len) {
    smart_str out = {0};
    zend_execute_data *target = shown_frame_at(index);
    if (!target) {
        return no_frame(&out, len);
    }
    struct in_frame run;
    enter_frame(&run, target);
    zval result;
    if (evaluate_code(&out, code, code_len, statements, &result) == 0) {
        pack_result(&out, &result);
        zval_ptr_dtor(&result);
    }
    leave_frame(&run);
    return malloc_copy(&out, len);
}

/** The class whose scope may write property `name` of `object`, which may be private to a parent. */
static zend_class_entry *property_scope(zend_object *object, const char *name, size_t name_len) {
    HashTable *properties = properties_of(object);
    zend_string *key;
    if (properties) {
        ZEND_HASH_FOREACH_STR_KEY(properties, key) {
            const char *class_name, *property;
            size_t property_len;
            if (!key || zend_unmangle_property_name_ex(key, &class_name, &property, &property_len) != SUCCESS
                || property_len != name_len || memcmp(property, name, name_len) != 0) {
                continue;
            }
            if (class_name && class_name[0] != '*') {
                zend_class_entry *declaring = zend_hash_str_find_ptr_lc(EG(class_table), class_name, strlen(class_name));
                return declaring ? declaring : object->ce;
            }
            break;
        } ZEND_HASH_FOREACH_END();
    }
    return object->ce;
}

/**
 * Sets element or property `name` of the array or object `reference` refers to, to the value of
 * `code` (evaluated in the stopped frame). Typed and readonly properties check as in PHP. Packs the
 * new value as tenma_inspect_evaluate does.
 */
char *tenma_inspect_set_child(uint32_t reference, const char *name, size_t name_len, const char *code, size_t code_len, size_t *len) {
    smart_str out = {0};
    zend_execute_data *target = shown_frame_at(0);
    if (!target || reference == 0 || reference > expandable_count) {
        return no_frame(&out, len);
    }
    struct in_frame run;
    enter_frame(&run, target);
    zval value;
    if (evaluate_code(&out, code, code_len, false, &value) == 0) {
        zval *container = lookup(reference, true);
        if (container && Z_TYPE_P(container) == IS_ARRAY) {
            SEPARATE_ARRAY(container);
            pack_result(&out, zend_symtable_str_update(Z_ARRVAL_P(container), name, name_len, &value));
        } else if (container && Z_TYPE_P(container) == IS_OBJECT) {
            zend_object *object = Z_OBJ_P(container);
            zend_string *property = zend_string_init(name, name_len, 0);
            zend_class_entry *scope = property_scope(object, name, name_len);
            zend_update_property_ex(scope, object, property, &value);
            zval_ptr_dtor(&value);
            if (!failed(&out, false)) {
                zval read;
                pack_result(&out, zend_read_property_ex(scope, object, property, 1, &read));
            }
            zend_string_release(property);
        } else {
            zval_ptr_dtor(&value);
            failed(&out, true);
        }
    }
    leave_frame(&run);
    return malloc_copy(&out, len);
}

/** Tenma::debugger(): stops here if a debugger is connected, like `debugger;` in JavaScript. */
extern void tenma_rs_debugger(uintptr_t fiber);

PHP_FUNCTION(tenma_reloaded_by) {
    ZEND_PARSE_PARAMETERS_NONE();
    char buffer[4096];
    size_t len = tenma_rs_reloaded_by(buffer, sizeof buffer);
    if (len == SIZE_MAX) {
        RETURN_NULL();
    }
    if (len <= sizeof buffer) {
        RETURN_STRINGL(buffer, len);
    }
    // A longer path: once more with room for it
    zend_string *path = zend_string_alloc(len, 0);
    if (tenma_rs_reloaded_by(ZSTR_VAL(path), len) != len) {
        zend_string_release(path);
        RETURN_NULL();
    }
    ZSTR_VAL(path)[len] = '\0';
    RETURN_NEW_STR(path);
}

PHP_FUNCTION(tenma_debugger) {
    ZEND_PARSE_PARAMETERS_NONE();
    if (!inspecting || evaluating) {
        return;
    }
    zend_execute_data *outer = paused;
    paused = EG(current_execute_data);
    tenma_rs_debugger((uintptr_t) EG(active_fiber));
    paused = outer;
    bail_after_fatal_evaluation();
}

/** Where a thrown exception ends up, see exception_fate. */
enum fate { CAUGHT_INSIDE, CAUGHT, UNCAUGHT };

/**
 * Follows a thrown exception up the stopped fiber's frames: caught inside the stdlib before it
 * reaches PHP code of the app (Tenma's own business), caught by the app, or not: it leaves the app's
 * code into the stdlib (which turns it into a 500 or a task's error) or the fiber.
 */
static enum fate exception_fate(zend_object *exception) {
    int in_app = 0;
    FOREACH_FRAME(paused, EG(active_fiber) ? EG(active_fiber)->stack_bottom : NULL, frame) {
        if (!frame->func || !ZEND_USER_CODE(frame->func->type) || !frame->opline) {
            continue;
        }
        if (shown_frame(frame)) {
            in_app = 1;
        } else if (in_app) {
            return UNCAUGHT;
        }
        zend_op_array *op_array = &frame->func->op_array;
        uint32_t at = frame->opline - op_array->opcodes;
        for (int i = 0; i < op_array->last_try_catch; i++) {
            zend_try_catch_element *block = &op_array->try_catch_array[i];
            if (!block->catch_op || at < block->try_op || at >= block->catch_op) {
                continue;
            }
            // The catch clauses in order, each with the class it catches
            for (const zend_op *clause = &op_array->opcodes[block->catch_op]; clause->opcode == ZEND_CATCH; clause = OP_JMP_ADDR(clause, clause->op2)) {
                zend_class_entry *catches = zend_hash_find_ptr(EG(class_table), Z_STR_P(RT_CONSTANT(clause, clause->op1) + 1));
                if (catches && instanceof_function(exception->ce, catches)) {
                    return in_app ? CAUGHT : CAUGHT_INSIDE;
                }
                if (clause->extended_value & ZEND_LAST_CATCH) {
                    break;
                }
            }
        }
    }
    return in_app ? UNCAUGHT : CAUGHT_INSIDE;
}

extern void tenma_rs_exception(const char *description, size_t description_len, const char *classes, size_t classes_len, bool uncaught);


/** PHP throws: the frames are still alive here, so the debugger may stop with them. */
static void inspect_throw(zend_object *exception) {
    if (previous_throw_hook) {
        previous_throw_hook(exception);
    }
    // Tenma\Cancelled ends a task, as Tenma intends
    if (!inspecting || evaluating || zend_string_equals_literal_ci(exception->ce->name, "Tenma\\Cancelled")) {
        return;
    }
    zend_execute_data *outer = paused;
    paused = EG(current_execute_data);
    enum fate fate = exception_fate(exception);
    if (fate != CAUGHT_INSIDE) {
        zend_string *message = exception_message(exception);
        smart_str description = {0};
        smart_str_append(&description, exception->ce->name);
        if (message && ZSTR_LEN(message) > 0) {
            smart_str_appends(&description, ": ");
            smart_str_append(&description, message);
        }
        smart_str_0(&description);
        // The class, its parents and its interfaces, for exception breakpoints limited to classes
        smart_str classes = {0};
        for (zend_class_entry *class = exception->ce; class; class = class->parent) {
            add_field(&classes, ZSTR_VAL(class->name), ZSTR_LEN(class->name));
        }
        for (uint32_t i = 0; i < exception->ce->num_interfaces; i++) {
            add_field(&classes, ZSTR_VAL(exception->ce->interfaces[i]->name), ZSTR_LEN(exception->ce->interfaces[i]->name));
        }
        smart_str_0(&classes);
        tenma_rs_exception(ZSTR_VAL(description.s), ZSTR_LEN(description.s), ZSTR_VAL(classes.s), ZSTR_LEN(classes.s), fate == UNCAUGHT);
        smart_str_free(&classes);
        smart_str_free(&description);
    }
    paused = outer;
    bail_after_fatal_evaluation();
}

/** Prints an uncaught exception and clears it. */
static void report_exception(void) {
    if (EG(exception)) {
        zend_exception_error(EG(exception), E_WARNING);
    }
}

/** Runs PHP code without <?php, such as the bundled standard library. 0 on success. */
int tenma_eval(const char *code, size_t len, const char *name) {
    volatile int result = -1;
    zend_try {
        result = zend_eval_stringl((char *) code, len, NULL, name) == SUCCESS && !EG(exception) ? 0 : -1;
        report_exception();
    } zend_catch {
        EG(current_execute_data) = NULL;
    } zend_end_try();
    return result;
}

/**
 * Like the PHP CLI: $_SERVER knows the running script, $argv and $argc its arguments (argv[0] is
 * the script as given on the command line). Frameworks use this to find their files and tasks.
 */
static void register_script(const char *path, const char *const *argv, int argc) {
    zval args;
    array_init(&args);
    for (int i = 0; i < argc; i++) {
        add_next_index_string(&args, argv[i]);
    }
    zval count;
    ZVAL_LONG(&count, argc);
    zend_hash_str_update(&EG(symbol_table), ZEND_STRL("argv"), &args);
    zend_hash_str_update(&EG(symbol_table), ZEND_STRL("argc"), &count);

    zend_is_auto_global_str(ZEND_STRL("_SERVER"));
    zval *server = zend_hash_str_find(&EG(symbol_table), ZEND_STRL("_SERVER"));
    if (!server || Z_TYPE_P(server) != IS_ARRAY) {
        return;
    }
    SEPARATE_ARRAY(server);
    const char *keys[] = {"SCRIPT_FILENAME", "SCRIPT_NAME", "PHP_SELF", "PATH_TRANSLATED"};
    for (size_t i = 0; i < sizeof(keys) / sizeof(*keys); i++) {
        add_assoc_string(server, keys[i], path);
    }
    add_assoc_string(server, "DOCUMENT_ROOT", "");
    add_assoc_string(server, "SERVER_NAME", server_name);
    Z_TRY_ADDREF(args);
    add_assoc_zval(server, "argv", &args);
    add_assoc_long(server, "argc", argc);
}

/** Runs a PHP file (absolute path). Returns the exit status, 255 on a fatal error. */
int tenma_execute(const char *path, const char *const *argv, int argc) {
    script_exited = 0;
    register_script(path, argv, argc);
    zend_file_handle file;
    zend_stream_init_filename(&file, path);
    volatile int status = 255;
    zend_try {
        php_execute_script(&file);
        status = EG(exit_status);
    } zend_catch {
        EG(current_execute_data) = NULL;
    } zend_end_try();
    zend_destroy_file_handle(&file);
    return status;
}

static void add_str(zval *array, const char *key, tenma_str value) {
    add_assoc_stringl(array, key, value.ptr, value.len);
}

/**
 * The embed SAPI considers headers already sent. Before each request: no headers, status 200,
 * nothing sent. This makes header(), headers_list(), headers_sent() and
 * http_response_code() work as under FPM. no_headers stays set so PHP never sends by itself.
 */
static void reset_headers(void) {
    zend_llist_clean(&SG(sapi_headers).headers);
    SG(sapi_headers).http_response_code = 200;
    SG(headers_sent) = 0;
    // The output layer also remembers "already sent", e.g. from an echo at startup; otherwise
    // PHP warns as soon as header('Content-Length: …') tries to change zlib.output_compression
    OG(flags) &= ~PHP_OUTPUT_SENT;
}

/** Packs a list of strings as s\0s\0 … into a new buffer. */
static char *pack_lines(HashTable *lines, size_t *packed_len) {
    size_t length = 0;
    zval *line;
    // A null byte separates the lines: a line with its own null byte would start a foreign header
#define PACKABLE(line) (Z_TYPE_P(line) == IS_STRING && !memchr(Z_STRVAL_P(line), 0, Z_STRLEN_P(line)))
    ZEND_HASH_FOREACH_VAL(lines, line) {
        if (PACKABLE(line)) {
            length += Z_STRLEN_P(line) + 1;
        }
    } ZEND_HASH_FOREACH_END();
    char *packed = malloc(length ? length : 1);
    char *cursor = packed;
    ZEND_HASH_FOREACH_VAL(lines, line) {
        if (PACKABLE(line)) {
            memcpy(cursor, Z_STRVAL_P(line), Z_STRLEN_P(line) + 1);
            cursor += Z_STRLEN_P(line) + 1;
        }
    } ZEND_HASH_FOREACH_END();
#undef PACKABLE
    *packed_len = length;
    return packed;
}

/** Reads [int $status, list<string> $headers, string $body] from the standard library's return value. */
static int read_response(zval *value, tenma_response *response) {
    if (Z_TYPE_P(value) != IS_ARRAY) {
        return 0;
    }
    zval *status = zend_hash_index_find(Z_ARRVAL_P(value), 0);
    zval *headers = zend_hash_index_find(Z_ARRVAL_P(value), 1);
    zval *body = zend_hash_index_find(Z_ARRVAL_P(value), 2);
    if (!status || Z_TYPE_P(status) != IS_LONG || !headers || Z_TYPE_P(headers) != IS_ARRAY || !body || Z_TYPE_P(body) != IS_STRING) {
        return 0;
    }

    response->status = (uint16_t) Z_LVAL_P(status);
    response->headers = pack_lines(Z_ARRVAL_P(headers), &response->headers_len);
    response->body_len = Z_STRLEN_P(body);
    response->body = malloc(response->body_len ? response->body_len : 1);
    memcpy(response->body, Z_STRVAL_P(body), response->body_len);
    return 1;
}

/** tenma_respond(int $rid, array $response): void. Answers request $rid with [status, headers, body]. */
PHP_FUNCTION(tenma_respond) {
    zend_long rid;
    zval *tuple;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(rid)
        Z_PARAM_ARRAY(tuple)
    ZEND_PARSE_PARAMETERS_END();

    tenma_response response;
    if (!read_response(tuple, &response)) {
        zend_argument_value_error(2, "must be [int $status, list<string> $headers, string $body]");
        RETURN_THROWS();
    }
    responded_rid = (uint64_t) rid;
    tenma_rs_respond((uint64_t) rid, &response);
}

/** tenma_op_sleep(int $milliseconds): int. Starts a timer, returns the op ID. */
PHP_FUNCTION(tenma_op_sleep) {
    zend_long milliseconds;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(milliseconds)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_LONG((zend_long) tenma_rs_sleep(milliseconds < 0 ? 0 : milliseconds));
}

/** tenma_op_fetch(string $method, string $url, list<string> $headers, string $body, int $timeoutMs): int. Starts an HTTP request. */
PHP_FUNCTION(tenma_op_fetch) {
    zend_string *method, *url, *body;
    zval *headers;
    zend_long timeout_ms;
    ZEND_PARSE_PARAMETERS_START(5, 5)
        Z_PARAM_STR(method)
        Z_PARAM_STR(url)
        Z_PARAM_ARRAY(headers)
        Z_PARAM_STR(body)
        Z_PARAM_LONG(timeout_ms)
    ZEND_PARSE_PARAMETERS_END();

    size_t packed_len;
    char *packed = pack_lines(Z_ARRVAL_P(headers), &packed_len);
    uint64_t op = tenma_rs_fetch(ZSTR_VAL(method), ZSTR_LEN(method), ZSTR_VAL(url), ZSTR_LEN(url),
        packed, packed_len, ZSTR_VAL(body), ZSTR_LEN(body), timeout_ms > 0 ? (uint64_t) timeout_ms : 1);
    free(packed);
    RETURN_LONG((zend_long) op);
}

/**
 * Fields end up in PHP's internal $_POST via the filter extension (plus raw in its own array),
 * files in PHP's internal $_FILES: clears everything from the previous request, which
 * filter_input(INPUT_POST) would show otherwise.
 */
static void forget_post(void) {
    zval *post = &PG(http_globals)[TRACK_VARS_POST];
    zval *files = &PG(http_globals)[TRACK_VARS_FILES];
    zval_ptr_dtor(post);
    array_init(post);
    zval_ptr_dtor(files);
    array_init(files);
    zval_ptr_dtor(&IF_G(post_array));
    ZVAL_UNDEF(&IF_G(post_array));
}

/**
 * tenma_set_input(string $body): void. Begins a classic request: php://input yields $body,
 * uploads and temp files of the previous request go away.
 */
PHP_FUNCTION(tenma_set_input) {
    zend_string *body;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(body)
    ZEND_PARSE_PARAMETERS_END();

    if (SG(rfc1867_uploaded_files)) {
        destroy_uploaded_files_hash();
    }
    forget_post();
    if (SG(request_info).request_body) {
        php_stream_close(SG(request_info).request_body);
    }
    php_stream *stream = php_stream_temp_create_ex(TEMP_STREAM_DEFAULT, SAPI_POST_BLOCK_SIZE, PG(upload_tmp_dir));
    php_stream_write(stream, ZSTR_VAL(body), ZSTR_LEN(body));
    php_stream_rewind(stream);
    SG(request_info).request_body = stream;
    SG(request_info).content_length = (zend_long) ZSTR_LEN(body);
}

/**
 * tenma_parse_multipart(string $contentType, string $body): array{0: array, 1: array}. Splits a
 * multipart/form-data body into [$_POST, $_FILES] with PHP's own parser; uploaded files are ready
 * as temp files, and is_uploaded_file() and move_uploaded_file() know them.
 */
PHP_FUNCTION(tenma_parse_multipart) {
    zend_string *content_type, *body;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(content_type)
        Z_PARAM_STR(body)
    ZEND_PARSE_PARAMETERS_END();

    forget_post();
    zval *post = &PG(http_globals)[TRACK_VARS_POST];
    zval *files = &PG(http_globals)[TRACK_VARS_FILES];
    post_data = ZSTR_VAL(body);
    post_len = ZSTR_LEN(body);
    post_pos = 0;
    SG(read_post_bytes) = 0;
    SG(post_read) = 0;

    char *type = estrndup(ZSTR_VAL(content_type), ZSTR_LEN(content_type));
    rfc1867_post_handler(type, post);
    efree(type);
    post_data = NULL;

    array_init(return_value);
    Z_TRY_ADDREF_P(post);
    add_next_index_zval(return_value, post);
    Z_TRY_ADDREF_P(files);
    add_next_index_zval(return_value, files);
}

/**
 * tenma_forget_session(): void. The session extension keeps the ID until the PHP request ends,
 * and in Tenma that lasts as long as the worker. Without an ID, session_start() looks in the cookie
 * again on the next request. Only call this when the session is closed.
 */
PHP_FUNCTION(tenma_forget_session) {
    ZEND_PARSE_PARAMETERS_NONE();
    if (PS(session_status) != php_session_active && PS(id)) {
        zend_string_release(PS(id));
        PS(id) = NULL;
    }
}

/** tenma_stream_start(int $rid, int $status, list<string> $headers): bool. Sends the head; the body follows. */
PHP_FUNCTION(tenma_stream_start) {
    zend_long rid, status;
    zval *headers;
    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_LONG(rid)
        Z_PARAM_LONG(status)
        Z_PARAM_ARRAY(headers)
    ZEND_PARSE_PARAMETERS_END();
    size_t packed_len;
    char *packed = pack_lines(Z_ARRVAL_P(headers), &packed_len);
    int started = tenma_rs_stream_start((uint64_t) rid, (uint16_t) status, packed, packed_len);
    free(packed);
    RETURN_BOOL(started);
}

/** tenma_stream_write(int $rid, string $chunk): int. 0 written, above 0 an op to wait on, -1 client gone. */
PHP_FUNCTION(tenma_stream_write) {
    zend_long rid;
    zend_string *chunk;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(rid)
        Z_PARAM_STR(chunk)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_LONG((zend_long) tenma_rs_stream_write((uint64_t) rid, ZSTR_VAL(chunk), ZSTR_LEN(chunk)));
}

PHP_FUNCTION(tenma_stream_end) {
    zend_long rid;
    bool finished;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(rid)
        Z_PARAM_BOOL(finished)
    ZEND_PARSE_PARAMETERS_END();
    tenma_rs_stream_end((uint64_t) rid, finished);
}

/** tenma_ws_accept(int $rid): bool. Responds with 101; from then on the connection is a WebSocket. */
PHP_FUNCTION(tenma_ws_accept) {
    zend_long rid;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(rid)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(tenma_rs_ws_accept((uint64_t) rid));
}

PHP_FUNCTION(tenma_ws_send) {
    zend_long rid;
    zend_string *text;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(rid)
        Z_PARAM_STR(text)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_BOOL(tenma_rs_ws_send((uint64_t) rid, ZSTR_VAL(text), ZSTR_LEN(text)));
}

/** tenma_ws_receive(int $rid): int. Op for the next message; 0 if the socket does not exist. */
PHP_FUNCTION(tenma_ws_receive) {
    zend_long rid;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(rid)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_LONG((zend_long) tenma_rs_ws_receive((uint64_t) rid));
}

PHP_FUNCTION(tenma_ws_close) {
    zend_long rid;
    bool finished;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_LONG(rid)
        Z_PARAM_BOOL(finished)
    ZEND_PARSE_PARAMETERS_END();
    tenma_rs_ws_close((uint64_t) rid, finished);
}

/** tenma_op_db(string $op, string $target, string $sql, string $paramsJson): int. Starts a database op. */
PHP_FUNCTION(tenma_op_db) {
    zend_string *op, *target, *sql, *params;
    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STR(op)
        Z_PARAM_STR(target)
        Z_PARAM_STR(sql)
        Z_PARAM_STR(params)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_LONG((zend_long) tenma_rs_db(ZSTR_VAL(op), ZSTR_LEN(op), ZSTR_VAL(target), ZSTR_LEN(target),
        ZSTR_VAL(sql), ZSTR_LEN(sql), ZSTR_VAL(params), ZSTR_LEN(params)));
}

/**
 * A classic request ends as under FPM: only once no PHP code is running does Tenma run the
 * request's shutdown functions and then collect the response (finish_classic). This way PHP's own
 * rules for exceptions, exit() and fatal errors in them apply unchanged.
 *
 * tenma_classic_begin() marks the start and sets aside the shutdown functions already
 * registered (e.g. at script startup); they still run only when the worker ends.
 */

/** Records the ini values already changed, e.g. at script startup. */
static void snapshot_ini(void) {
    ALLOC_HASHTABLE(ini_snapshot);
    zend_hash_init(ini_snapshot, 8, NULL, ZVAL_PTR_DTOR, 0);
    if (!EG(modified_ini_directives)) {
        return;
    }
    zend_ini_entry *entry;
    ZEND_HASH_MAP_FOREACH_PTR(EG(modified_ini_directives), entry) {
        zval value;
        if (entry->value) {
            ZVAL_STR_COPY(&value, entry->value);
        } else {
            ZVAL_NULL(&value);
        }
        zend_hash_update(ini_snapshot, entry->name, &value);
    } ZEND_HASH_FOREACH_END();
}

/**
 * Resets what the request changed with ini_set() or set_time_limit(), as PHP does at the end of every
 * request. Resetting max_execution_time also turns off the timer.
 */
static void restore_ini(void) {
    if (!ini_snapshot) {
        return;
    }
    if (EG(modified_ini_directives)) {
        // Collect the names first: zend_restore_ini_entry deletes from the table we iterate over
        zend_string **names = safe_emalloc(zend_hash_num_elements(EG(modified_ini_directives)), sizeof(zend_string *), 0);
        uint32_t count = 0;
        zend_ini_entry *entry;
        ZEND_HASH_MAP_FOREACH_PTR(EG(modified_ini_directives), entry) {
            names[count++] = zend_string_copy(entry->name);
        } ZEND_HASH_FOREACH_END();
        for (uint32_t i = 0; i < count; i++) {
            zval *before = zend_hash_find(ini_snapshot, names[i]);
            if (!before) {
                zend_restore_ini_entry(names[i], ZEND_INI_STAGE_RUNTIME);
            } else if (Z_TYPE_P(before) == IS_STRING) {
                zend_ini_entry *now = zend_hash_find_ptr(EG(ini_directives), names[i]);
                if (now && (!now->value || !zend_string_equals(now->value, Z_STR_P(before)))) {
                    zend_alter_ini_entry_ex(names[i], Z_STR_P(before), ZEND_INI_SYSTEM, ZEND_INI_STAGE_RUNTIME, 1);
                }
            }
            zend_string_release(names[i]);
        }
        efree(names);
    }
    zend_hash_destroy(ini_snapshot);
    FREE_HASHTABLE(ini_snapshot);
    ini_snapshot = NULL;
}

/**
 * Closes the request's php://input streams. They point to the body stream without refcounting,
 * and the next request replaces it; left open, they would read freed memory.
 */
static void close_input_streams(void) {
    uint32_t capacity = zend_hash_num_elements(&EG(regular_list)), count = 0;
    if (!capacity) {
        return;
    }
    php_stream **streams = safe_emalloc(capacity, sizeof(php_stream *), 0);
    zend_resource *resource;
    ZEND_HASH_FOREACH_PTR(&EG(regular_list), resource) {
        php_stream *stream = resource->ptr;
        // NO_FCLOSE: a C owner holds the stream by pointer without a reference (SplFileObject, a libxml
        // reader) and closes it itself; closing it here would leave that owner with freed memory
        if (resource->type == php_file_le_stream() && stream && stream->ops && stream->ops->label && strcmp(stream->ops->label, "Input") == 0
            && !(stream->flags & PHP_STREAM_FLAG_NO_FCLOSE)) {
            streams[count++] = stream;
        }
    } ZEND_HASH_FOREACH_END();
    for (uint32_t i = 0; i < count; i++) {
        php_stream_close(streams[i]);
    }
    efree(streams);
}

PHP_FUNCTION(tenma_classic_begin) {
    ZEND_PARSE_PARAMETERS_NONE();
    outer_shutdown_functions = BG(user_shutdown_function_names);
    BG(user_shutdown_function_names) = NULL;
    smart_str_free(&classic_output);
    snapshot_ini();
    classic_open = 1;
}

/** tenma_take_output(): string. The classic request's output collected so far; empty afterwards. */
PHP_FUNCTION(tenma_take_output) {
    ZEND_PARSE_PARAMETERS_NONE();
    zend_string *output = smart_str_extract(&classic_output);
    RETURN_STR(output);
}

/** tenma_db_abandon(int $rid): void. Discards a request's open transactions, e.g. after exit(). */
PHP_FUNCTION(tenma_db_abandon) {
    zend_long rid;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(rid)
    ZEND_PARSE_PARAMETERS_END();
    tenma_rs_db_abandon((uint64_t) rid);
}

/**
 * tenma_parse_cookies(string $header, bool $global): array. Parses a Cookie header with PHP's own parser, exactly
 * like $_COOKIE under FPM: the first of several same-named cookies wins (protection against cookies
 * from sibling subdomains), names like a[b] become arrays, dots and spaces become underscores.
 * With $global they stay in PHP's cookie globals (classic mode, for filter_input()).
 */
PHP_FUNCTION(tenma_parse_cookies) {
    zend_string *header;
    bool global;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(header)
        Z_PARAM_BOOL(global)
    ZEND_PARSE_PARAMETERS_END();
    // The filter extension also collects cookies raw; without clearing, that would grow with every request
    zval_ptr_dtor(&IF_G(cookie_array));
    ZVAL_UNDEF(&IF_G(cookie_array));
    SG(request_info).cookie_data = ZSTR_VAL(header);
    sapi_module.treat_data(PARSE_COOKIE, NULL, NULL);
    SG(request_info).cookie_data = NULL;
    RETVAL_COPY(&PG(http_globals)[TRACK_VARS_COOKIE]);
    if (!global) {
        // Fiber mode: many requests share the interpreter, so one request's cookies must not
        // reach another through filter_input(INPUT_COOKIE)
        zval_ptr_dtor(&IF_G(cookie_array));
        ZVAL_UNDEF(&IF_G(cookie_array));
        zval_ptr_dtor(&PG(http_globals)[TRACK_VARS_COOKIE]);
        array_init(&PG(http_globals)[TRACK_VARS_COOKIE]);
    }
}

/** tenma_op_cancel(int $op): void. Cancels a running op, e.g. the sleep of a cancelled timer. */
PHP_FUNCTION(tenma_op_cancel) {
    zend_long op;
    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_LONG(op)
    ZEND_PARSE_PARAMETERS_END();
    tenma_rs_op_cancel((uint64_t) op);
}

/** tenma_exit_process(): void. exit() outside a request: tenma exits with the exit status. */
PHP_FUNCTION(tenma_exit_process) {
    ZEND_PARSE_PARAMETERS_NONE();
    // Like the PHP CLI: only the lower 8 bits count, exit(256) exits with 0
    tenma_rs_exit(EG(exit_status) & 0xff);
}

/**
 * Resolves a path like PHP's file functions do: relative to the interpreter's working directory (chdir())
 * and checked against open_basedir. NULL after a thrown exception.
 */
static char *resolve_path(zend_string *path) {
    char *resolved = expand_filepath(ZSTR_VAL(path), NULL);
    if (!resolved) {
        zend_throw_exception_ex(spl_ce_RuntimeException, 0, "Cannot resolve path: %s", ZSTR_VAL(path));
        return NULL;
    }
    if (php_check_open_basedir(resolved)) {
        zend_throw_exception_ex(spl_ce_RuntimeException, 0, "open_basedir forbids %s", resolved);
        efree(resolved);
        return NULL;
    }
    return resolved;
}

/**
 * tenma_op_fs(string $op, string $path, string $second, bool $flag, string $data): int. Starts a
 * file system op; $second is the destination for rename and copy, $flag means recursive. PHP already
 * rejects null bytes in paths at call time.
 */
PHP_FUNCTION(tenma_op_fs) {
    zend_string *op, *path, *second, *data;
    bool flag;
    ZEND_PARSE_PARAMETERS_START(5, 5)
        Z_PARAM_STR(op)
        Z_PARAM_PATH_STR(path)
        Z_PARAM_PATH_STR(second)
        Z_PARAM_BOOL(flag)
        Z_PARAM_STR(data)
    ZEND_PARSE_PARAMETERS_END();

    char *target = resolve_path(path);
    if (!target) {
        RETURN_THROWS();
    }
    char *destination = NULL;
    if (ZSTR_LEN(second) > 0 && !(destination = resolve_path(second))) {
        efree(target);
        RETURN_THROWS();
    }
    uint64_t id = tenma_rs_fs(ZSTR_VAL(op), ZSTR_LEN(op), target, strlen(target),
        destination ? destination : "", destination ? strlen(destination) : 0, flag, ZSTR_VAL(data), ZSTR_LEN(data));
    efree(target);
    if (destination) {
        efree(destination);
    }
    RETURN_LONG((zend_long) id);
}

/** tenma_pubsub(string $op, int $id, string $channel, string $message): int. Messages between workers (src/pubsub.rs). */
PHP_FUNCTION(tenma_pubsub) {
    zend_string *op, *channel, *message;
    zend_long id;
    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STR(op)
        Z_PARAM_LONG(id)
        Z_PARAM_STR(channel)
        Z_PARAM_STR(message)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_LONG((zend_long) tenma_rs_pubsub(ZSTR_VAL(op), ZSTR_LEN(op), (uint64_t) id, ZSTR_VAL(channel), ZSTR_LEN(channel),
        ZSTR_VAL(message), ZSTR_LEN(message)));
}

/** tenma_op_dev(string $op, string $arg): int. Starts an op for the hooks of tenma.dev.php (src/dev.rs). */
PHP_FUNCTION(tenma_op_dev) {
    zend_string *op, *arg;
    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STR(op)
        Z_PARAM_STR(arg)
    ZEND_PARSE_PARAMETERS_END();
    RETURN_LONG((zend_long) tenma_rs_dev(ZSTR_VAL(op), ZSTR_LEN(op), ZSTR_VAL(arg), ZSTR_LEN(arg)));
}

/** Forgets the last PHP error so after_bailout does not attribute it to a later request. */
static void forget_last_error(void) {
    PG(last_error_type) = 0;
    if (PG(last_error_message)) {
        zend_string_release(PG(last_error_message));
        PG(last_error_message) = NULL;
    }
}

/** How a call into PHP ended. */
enum outcome { CALL_FATAL = -1, CALL_EXCEPTION = 0, CALL_OK = 1, CALL_EXIT = 2 };

/**
 * After a zend_bailout: if it was a fatal error, the interpreter is unusable after this request.
 * Otherwise it was exit() or die(): PHP has unwound everything cleanly and only jumps out
 * because Tenma called the handler from outside.
 */
static enum outcome after_bailout(void) {
    // The longjmp skipped the frames; the pointer points into a C stack that no longer exists.
    // PHP itself resets it at this point (php_request_shutdown: "points into nirvana").
    EG(current_execute_data) = NULL;
    if (PG(last_error_type) & E_FATAL_ERRORS) {
        return CALL_FATAL;
    }
    CG(unclean_shutdown) = 0;
    // zend_bailout switched the cycle collector off; PHP only switches it on at the next request startup
    gc_protect(0);
    return CALL_EXIT;
}

/** Calls a standard library function and reports how it ended. */
static enum outcome call_stdlib(const char *name, uint32_t count, zval *arguments) {
    zval function, retval;
    ZVAL_STRING(&function, name);
    ZVAL_UNDEF(&retval);
    volatile enum outcome result = CALL_FATAL;
    forget_last_error();
    zend_try {
        call_user_function(NULL, NULL, &function, &retval, count, arguments);
        result = EG(exception) ? CALL_EXCEPTION : CALL_OK;
        report_exception();
    } zend_catch {
        result = after_bailout();
    } zend_end_try();
    zval_ptr_dtor(&function);
    zval_ptr_dtor(&retval);
    return result;
}

/**
 * exit() in fiber mode ends only the request that was running: Tenma\Internal\exited() answers it.
 * Returns what tenma_handle and complete report.
 */
static int after_exit_in_fiber(void) {
    return call_stdlib("Tenma\\Internal\\exited", 0, NULL) == CALL_FATAL ? -1 : 1;
}

/**
 * Resumes whatever was woken up (Tenma\Internal\drain) until nothing is waiting. If one of the
 * resumed fibers ends with exit(), it carries on with the rest afterwards. -1: fatal.
 */
static int drain_ready(void) {
    for (int round = 0; round < 10000; round++) {
        enum outcome result = call_stdlib("Tenma\\Internal\\drain", 0, NULL);
        if (result == CALL_EXIT) {
            if (after_exit_in_fiber() < 0) {
                return -1;
            }
            continue;
        }
        return result == CALL_FATAL ? -1 : 0;
    }
    return 0;
}

/**
 * What tenma_handle, complete and tenma_started report after a call into the fiber world: 1, 0
 * (exception) or -1 (fatal). Afterwards everything the call woke up runs.
 */
static int fiber_result(enum outcome result) {
    int code = result == CALL_FATAL ? -1 : result == CALL_EXIT ? after_exit_in_fiber() : (int) result;
    if (code < 0) {
        return code;
    }
    return drain_ready() < 0 ? -1 : code;
}

/** The event loop is running: Tenma\Internal\started() starts timers and tasks from script startup. -1: fatal. */
int tenma_started(void) {
    return fiber_result(call_stdlib("Tenma\\Internal\\started", 0, NULL)) < 0 ? -1 : 0;
}


/**
 * Finishes a classic request at the top level: the request's shutdown functions, then the
 * response via Tenma\Http\finishClassic($fatal). 1: answered. -2: answered, but the
 * interpreter is unusable after a fatal error. 0: not answered, Rust sends 500.
 * -1: not answered and the interpreter is unusable.
 */
static int finish_classic(uint64_t rid, enum outcome handled) {
    int fatal = handled == CALL_FATAL;
    // Do not forget the last error: shutdown functions read it with error_get_last()
    // Has its own zend_try: exit() in there only ends the shutdown functions, as in PHP
    php_call_shutdown_functions();
    EG(current_execute_data) = NULL;
    php_free_shutdown_functions();
    if (PG(last_error_type) & E_FATAL_ERRORS) {
        fatal = 1;
    }
    BG(user_shutdown_function_names) = outer_shutdown_functions;
    outer_shutdown_functions = NULL;
    if (!fatal) {
        CG(unclean_shutdown) = 0;
        // exit() in a shutdown function bailed out, which switched the cycle collector off
        gc_protect(0);
    }
    // Whatever is still in output buffers belongs to the response, including buffers that cannot be removed.
    // Their handlers are user code: a fatal error in there must not bail out without an address
    zend_try {
        php_output_end_all();
    } zend_catch {
        if (after_bailout() == CALL_FATAL) {
            fatal = 1;
        }
    } zend_end_try();

    zval argument;
    ZVAL_BOOL(&argument, fatal);
    responded_rid = 0;
    enum outcome finished = call_stdlib("Tenma\\Http\\finishClassic", 1, &argument);
    int answered = responded_rid == rid;
    classic_open = 0;
    smart_str_free(&classic_output);
    // User code runs here too: stream filters when php://input closes, an error handler for the warning
    // when memory_limit can't go back below what is in use. A bailout needs an address to return to
    zend_try {
        close_input_streams();
        restore_ini();
    } zend_catch {
        if (after_bailout() == CALL_FATAL) {
            fatal = 1;
        }
    } zend_end_try();
    if (finished == CALL_FATAL) {
        return -1;
    }
    // If finishClassic itself failed, e.g. while saving the session, the client gets 500 instead of waiting
    if (!answered) {
        return fatal ? -1 : 0;
    }
    return fatal ? -2 : 1;
}

/**
 * Reports a finished op to PHP: calls Tenma\Internal\complete($op, $ok, $value), which resumes the
 * waiting fiber. Returns as tenma_handle does; takes ownership of $value.
 */
static int complete(uint64_t op, zend_bool ok, zval *value) {
    zval arguments[3];
    ZVAL_LONG(&arguments[0], (zend_long) op);
    ZVAL_BOOL(&arguments[1], ok);
    ZVAL_COPY_VALUE(&arguments[2], value);
    enum outcome result = call_stdlib("Tenma\\Internal\\complete", 3, arguments);
    zval_ptr_dtor(&arguments[2]);
    return fiber_result(result);
}

/** The client of request rid left before it got a response: cancel its tasks. */
int tenma_request_gone(uint64_t rid) {
    zval argument;
    ZVAL_LONG(&argument, (zend_long) rid);
    return fiber_result(call_stdlib("Tenma\\Internal\\requestGone", 1, &argument));
}

int tenma_complete_null(uint64_t op) {
    zval value;
    ZVAL_NULL(&value);
    return complete(op, 1, &value);
}

/**
 * Does a result of len bytes no longer fit under memory_limit? Then it becomes an error in the request,
 * instead of a fatal error when creating the string, which would restart the whole worker.
 */
static int too_big(size_t len) {
    return PG(memory_limit) > 0 && zend_memory_usage(1) + len + (1 << 20) > (size_t) PG(memory_limit);
}

int tenma_complete_error(uint64_t op, const char *message, size_t len, int code) {
    // An error arrives as [message, code]; Internal\await throws a RuntimeException with both
    zval value;
    array_init(&value);
    add_next_index_stringl(&value, message, len);
    add_next_index_long(&value, code);
    return complete(op, 0, &value);
}

static int complete_too_big(uint64_t op, size_t len) {
    char message[160];
    int written = snprintf(message, sizeof(message), "Result of %zu bytes does not fit under memory_limit", len);
    return tenma_complete_error(op, message, (size_t) written, 0);
}

int tenma_complete_string(uint64_t op, const char *text, size_t len) {
    if (too_big(len)) {
        return complete_too_big(op, len);
    }
    zval value;
    ZVAL_STRINGL(&value, text, len);
    return complete(op, 1, &value);
}

/** Response of a fetch as [int $status, list<string> $headers, string $body]; header lines as s\0s\0 … */
int tenma_complete_http(uint64_t op, uint16_t status, const char *headers, size_t headers_len, const char *body, size_t body_len) {
    if (too_big(headers_len + body_len)) {
        return complete_too_big(op, headers_len + body_len);
    }
    zval value, lines;
    array_init(&value);
    add_next_index_long(&value, status);
    array_init(&lines);
    for (size_t start = 0, i = 0; i < headers_len; i++) {
        if (headers[i] == 0) {
            add_next_index_stringl(&lines, headers + start, i - start);
            start = i + 1;
        }
    }
    add_next_index_zval(&value, &lines);
    add_next_index_stringl(&value, body, body_len);
    return complete(op, 1, &value);
}

/**
 * Calls a server's handler with a request and its ID. The response comes via
 * tenma_respond, immediately or later from a fiber. 1: handler completed. 0: exception, Rust
 * responds with 500. -1: fatal error, PHP is no longer usable. -2: answered, but afterwards
 * PHP is no longer usable (fatal error in a classic request).
 */
int tenma_handle(uint32_t handler, uint64_t rid, const tenma_request *request) {
    if (handler >= handler_count) {
        return 0;
    }
    // Allocating beyond memory_limit outside zend_try would end the whole process
    if (too_big(request->body.len + request->uri.len)) {
        return 0;
    }
    volatile enum outcome result = CALL_FATAL;
    zval arguments[2], headers, retval;
    zval *argument = &arguments[0];
    ZVAL_LONG(&arguments[1], (zend_long) rid);
    array_init(argument);
    add_str(argument, "method", request->method);
    add_str(argument, "uri", request->uri);
    add_str(argument, "body", request->body);
    add_str(argument, "remote_addr", request->remote_addr);
    add_assoc_long(argument, "remote_port", request->remote_port);
    add_str(argument, "server_addr", request->server_addr);
    add_assoc_long(argument, "server_port", request->server_port);
    array_init(&headers);
    for (size_t i = 0; i < request->header_count; i++) {
        const tenma_header *header = &request->headers[i];
        // A repeated header joins its values (names come lowercase), as proxies and PHP-FPM do
        zval *existing = zend_hash_str_find(Z_ARRVAL(headers), header->name.ptr, header->name.len);
        if (existing) {
            int cookie = header->name.len == 6 && memcmp(header->name.ptr, "cookie", 6) == 0;
            zend_string *joined = zend_string_concat3(Z_STRVAL_P(existing), Z_STRLEN_P(existing), cookie ? "; " : ", ", 2, header->value.ptr, header->value.len);
            zval_ptr_dtor(existing);
            ZVAL_STR(existing, joined);
        } else {
            add_assoc_stringl_ex(&headers, header->name.ptr, header->name.len, header->value.ptr, header->value.len);
        }
    }
    add_assoc_zval(argument, "headers", &headers);
    ZVAL_UNDEF(&retval);
    reset_headers();

    forget_last_error();
    zend_try {
        call_user_function(NULL, NULL, &handlers[handler], &retval, 2, arguments);
        result = EG(exception) ? CALL_EXCEPTION : CALL_OK;
        report_exception();
    } zend_catch {
        result = after_bailout();
    } zend_end_try();

    zval_ptr_dtor(argument);
    zval_ptr_dtor(&retval);
    if (classic_open) {
        return finish_classic(rid, result);
    }
    return fiber_result(result);
}

void tenma_response_free(tenma_response *response) {
    free(response->body);
    free(response->headers);
}
