/* The echo plugin of the test suite: built by tests/http.rs against tenma-plugin/tenma_plugin.h. */
#include <pthread.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

#include "tenma_plugin.h"

static const TenmaApi *api;
static const char *shutdown_file;

/* call: "echo" answers the input, "upper" answers it in upper case, anything else fails */
static int call(const char *fn, size_t fn_len, const uint8_t *in, size_t in_len, TenmaReply *out) {
    if (fn_len == 4 && memcmp(fn, "echo", 4) == 0) {
        api->reply(out, in, in_len);
        return 0;
    }
    if (fn_len == 5 && memcmp(fn, "upper", 5) == 0) {
        uint8_t *copy = malloc(in_len);
        for (size_t i = 0; i < in_len; i++) {
            copy[i] = in[i] >= 'a' && in[i] <= 'z' ? in[i] - 32 : in[i];
        }
        api->reply(out, copy, in_len);
        free(copy);
        return 0;
    }
    const char *message = "echo: unknown function";
    api->reply(out, (const uint8_t *) message, strlen(message));
    return 1;
}

struct later {
    TenmaToken *token;
    uint8_t *in;
    size_t len;
    int fail;
};

/* The asynchronous call completes from a thread of its own, after a moment */
static void *finish(void *arg) {
    struct later *job = arg;
    usleep(100000);
    if (job->fail) {
        const char *message = "echo: async failure";
        api->complete(job->token, (const uint8_t *) message, strlen(message), 1);
    } else {
        api->complete(job->token, job->in, job->len, 0);
    }
    free(job->in);
    free(job);
    return NULL;
}

static void call_async(const char *fn, size_t fn_len, const uint8_t *in, size_t in_len, TenmaToken *token) {
    struct later *job = malloc(sizeof(*job));
    job->token = token;
    job->in = malloc(in_len + 6);
    memcpy(job->in, "later:", 6);
    memcpy(job->in + 6, in, in_len);
    job->len = in_len + 6;
    job->fail = fn_len == 4 && memcmp(fn, "fail", 4) == 0;
    pthread_t thread;
    pthread_create(&thread, NULL, finish, job);
    pthread_detach(thread);
}

static void adopt(uint64_t conn) {
    char text[64];
    int len = snprintf(text, sizeof(text), "adopted %llu", (unsigned long long) conn);
    api->post("echo:events", 11, (const uint8_t *) text, (size_t) len);
    api->send(conn, (const uint8_t *) "hello from the plugin", 21, 0);
}

/* Echoes every message back, binary as binary; "buffered" answers the queued bytes; "bye" closes */
static void message(uint64_t conn, const uint8_t *ptr, size_t len, int binary) {
    if (len == 8 && memcmp(ptr, "buffered", 8) == 0) {
        char text[32];
        int n = snprintf(text, sizeof(text), "buffered %zu", api->buffered(conn));
        api->send(conn, (const uint8_t *) text, (size_t) n, 0);
        return;
    }
    if (len == 3 && memcmp(ptr, "bye", 3) == 0) {
        api->close(conn, 1000);
        return;
    }
    api->send(conn, ptr, len, binary);
}

static void closed(uint64_t conn) {
    char text[64];
    int len = snprintf(text, sizeof(text), "closed %llu", (unsigned long long) conn);
    api->post("echo:events", 11, (const uint8_t *) text, (size_t) len);
}

static void shutdown_plugin(void) {
    if (shutdown_file != NULL) {
        FILE *file = fopen(shutdown_file, "a");
        if (file != NULL) {
            fputs("plugin shut down\n", file);
            fclose(file);
        }
    }
}

static const TenmaPlugin plugin = {"echo", call, call_async, adopt, message, closed, shutdown_plugin};

const TenmaPlugin *tenma_plugin_init(const TenmaApi *given, uint32_t abi) {
    if (abi != TENMA_PLUGIN_ABI) {
        return NULL;
    }
    api = given;
    shutdown_file = getenv("ECHO_SHUTDOWN_FILE");
    api->log(TENMA_LOG_INFO, "echo plugin ready", 17);
    return &plugin;
}
