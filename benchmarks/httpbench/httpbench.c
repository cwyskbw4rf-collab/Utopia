// httpbench -- high-performance HTTP/1.1 GET load generator for the proxy benchmarks.
//
// Each worker thread owns a blocking TCP socket, builds a minimal HTTP/1.1 GET
// request by hand, reads the response (parses Content-Length for the body,
// falls back to "read until close" for chunked/unbounded bodies), and loops
// until the configured duration expires.
//
// Output is the same key=value format as tcpbench so the harness parser can be shared.
//
// Usage:
//   ./httpbench -h 127.0.0.1 -p 8080 -c 500 -d 30 -k
//   ./httpbench -h 127.0.0.1 -p 8080 -c 500 -d 30 -n /health
//
// Flags:
//   -h host         backend host (default 127.0.0.1)
//   -p port         backend port (default 8080)
//   -c concurrency  worker thread count (default 100)
//   -d duration     run duration seconds (default 10)
//   -k              keep-alive: reuse the connection across requests
//   -n path         request path (default "/")
//
// Compile:
//   gcc -O2 -pthread -o httpbench httpbench.c

#define _GNU_SOURCE
#include <arpa/inet.h>
#include <errno.h>
#include <netinet/in.h>
#include <netinet/tcp.h>
#include <pthread.h>
#include <stdatomic.h>
#include <stdbool.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/time.h>
#include <time.h>
#include <unistd.h>

#define RECV_BUF_SIZE 65536

typedef struct {
    const char *host;
    int port;
    int duration;
    bool keepalive;
    const char *path;
    char *request;
    size_t request_len;
    atomic_ullong total_ops;
    atomic_ullong total_bytes;
    atomic_ullong total_errors;
    atomic_int done;
} shared_t;

static uint64_t now_ns(void) {
    struct timespec ts;
    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (uint64_t)ts.tv_sec * 1000000000ULL + ts.tv_nsec;
}

static int connect_to(const char *host, int port) {
    int fd = socket(AF_INET, SOCK_STREAM, 0);
    if (fd < 0) return -1;

    int one = 1;
    setsockopt(fd, IPPROTO_TCP, TCP_NODELAY, &one, sizeof(one));

    struct sockaddr_in addr;
    memset(&addr, 0, sizeof(addr));
    addr.sin_family = AF_INET;
    addr.sin_port = htons(port);
    if (inet_pton(AF_INET, host, &addr.sin_addr) != 1) {
        close(fd);
        return -1;
    }

    if (connect(fd, (struct sockaddr *)&addr, sizeof(addr)) < 0) {
        close(fd);
        return -1;
    }
    return fd;
}

static ssize_t send_all(int fd, const void *buf, size_t len) {
    const char *p = buf;
    size_t left = len;
    while (left > 0) {
        ssize_t n = send(fd, p, left, 0);
        if (n <= 0) return -1;
        p += n;
        left -= (size_t)n;
    }
    return (ssize_t)len;
}

// Find a header value (case-insensitive) in [buf, buf+len). Returns -1 if not found.
// On success, writes value as an integer out.
static long find_content_length(const char *buf, size_t len) {
    static const char needle[] = "content-length:";
    size_t nlen = sizeof(needle) - 1;
    for (size_t i = 0; i + nlen < len; i++) {
        bool match = true;
        for (size_t j = 0; j < nlen; j++) {
            char c = buf[i + j];
            if (c >= 'A' && c <= 'Z') c = (char)(c + 32);
            if (c != needle[j]) {
                match = false;
                break;
            }
        }
        if (!match) continue;
        size_t p = i + nlen;
        while (p < len && (buf[p] == ' ' || buf[p] == '\t')) p++;
        long value = 0;
        bool any = false;
        while (p < len && buf[p] >= '0' && buf[p] <= '9') {
            value = value * 10 + (buf[p] - '0');
            p++;
            any = true;
        }
        if (any) return value;
    }
    return -1;
}

// Returns 1 on complete response read, 0 on closed/error.
// On success, bytes_out gets the number of body bytes consumed.
static int read_one_response(int fd, char *buf, size_t buf_size, unsigned long long *bytes_out) {
    size_t have = 0;
    ssize_t n;

    // Read headers until we see \r\n\r\n.
    size_t header_end = 0;
    while (header_end == 0) {
        if (have >= buf_size) return 0;
        n = recv(fd, buf + have, buf_size - have, 0);
        if (n <= 0) return 0;
        have += (size_t)n;
        if (have >= 4) {
            for (size_t i = 0; i + 3 < have; i++) {
                if (buf[i] == '\r' && buf[i+1] == '\n' && buf[i+2] == '\r' && buf[i+3] == '\n') {
                    header_end = i + 4;
                    break;
                }
            }
        }
    }

    long cl = find_content_length(buf, header_end);
    size_t body_have = have - header_end;

    if (cl >= 0) {
        size_t need = (size_t)cl;
        // Drain what's left over in our buffer, then read the remainder.
        size_t drained = body_have < need ? body_have : need;
        size_t remaining = need - drained;
        while (remaining > 0) {
            char tmp[RECV_BUF_SIZE];
            size_t chunk = remaining < sizeof(tmp) ? remaining : sizeof(tmp);
            n = recv(fd, tmp, chunk, 0);
            if (n <= 0) return 0;
            remaining -= (size_t)n;
        }
        *bytes_out = (unsigned long long)(header_end + need);
        return 1;
    }

    // No Content-Length: treat as "read until close" (covers chunked too, crudely).
    unsigned long long total = have;
    for (;;) {
        char tmp[RECV_BUF_SIZE];
        n = recv(fd, tmp, sizeof(tmp), 0);
        if (n <= 0) break;
        total += (unsigned long long)n;
    }
    *bytes_out = total;
    return 1; // peer closed; response ends.
}

static void *worker(void *arg) {
    shared_t *s = arg;
    unsigned long long ops = 0;
    unsigned long long bytes = 0;
    unsigned long long errors = 0;
    char *buf = malloc(RECV_BUF_SIZE);
    if (!buf) return NULL;

    int fd = -1;
    if (s->keepalive) {
        fd = connect_to(s->host, s->port);
        if (fd < 0) {
            atomic_fetch_add_explicit(&s->total_errors, 1, memory_order_relaxed);
            free(buf);
            return NULL;
        }
    }

    while (!atomic_load_explicit(&s->done, memory_order_relaxed)) {
        if (!s->keepalive) {
            fd = connect_to(s->host, s->port);
            if (fd < 0) { errors++; continue; }
        }

        if (send_all(fd, s->request, s->request_len) < 0) {
            errors++;
            close(fd);
            fd = -1;
            if (s->keepalive) {
                fd = connect_to(s->host, s->port);
                if (fd < 0) break;
            }
            continue;
        }

        unsigned long long got = 0;
        int ok = read_one_response(fd, buf, RECV_BUF_SIZE, &got);

        if (!s->keepalive) {
            close(fd);
            fd = -1;
        }

        if (!ok) {
            errors++;
            if (s->keepalive) {
                // reconnect for next iteration
                fd = connect_to(s->host, s->port);
                if (fd < 0) break;
            }
            continue;
        }

        ops++;
        bytes += got;
    }

    if (fd >= 0) close(fd);
    free(buf);

    atomic_fetch_add_explicit(&s->total_ops, ops, memory_order_relaxed);
    atomic_fetch_add_explicit(&s->total_bytes, bytes, memory_order_relaxed);
    atomic_fetch_add_explicit(&s->total_errors, errors, memory_order_relaxed);
    return NULL;
}

static void usage(const char *argv0) {
    fprintf(stderr,
            "usage: %s [-h host] [-p port] [-c concurrency] [-d duration] [-k] [-n path]\n",
            argv0);
    exit(1);
}

int main(int argc, char **argv) {
    shared_t s = {
        .host = "127.0.0.1",
        .port = 8080,
        .duration = 10,
        .keepalive = false,
        .path = "/",
    };
    int concurrency = 100;

    for (int i = 1; i < argc; i++) {
        if (strcmp(argv[i], "-h") == 0 && i + 1 < argc) {
            s.host = argv[++i];
        } else if (strcmp(argv[i], "-p") == 0 && i + 1 < argc) {
            s.port = atoi(argv[++i]);
        } else if (strcmp(argv[i], "-c") == 0 && i + 1 < argc) {
            concurrency = atoi(argv[++i]);
        } else if (strcmp(argv[i], "-d") == 0 && i + 1 < argc) {
            s.duration = atoi(argv[++i]);
        } else if (strcmp(argv[i], "-k") == 0) {
            s.keepalive = true;
        } else if (strcmp(argv[i], "-n") == 0 && i + 1 < argc) {
            s.path = argv[++i];
        } else {
            usage(argv[0]);
        }
    }

    // Build request once.
    const char *conn_hdr = s.keepalive ? "keep-alive" : "close";
    size_t cap = 256 + strlen(s.path) + strlen(s.host);
    s.request = malloc(cap);
    if (!s.request) {
        perror("malloc");
        return 1;
    }
    int n = snprintf(s.request, cap,
                     "GET %s HTTP/1.1\r\nHost: %s\r\nConnection: %s\r\nUser-Agent: httpbench\r\nAccept: */*\r\n\r\n",
                     s.path, s.host, conn_hdr);
    if (n < 0 || (size_t)n >= cap) {
        fprintf(stderr, "request formatting failed\n");
        return 1;
    }
    s.request_len = (size_t)n;

    atomic_store(&s.total_ops, 0);
    atomic_store(&s.total_bytes, 0);
    atomic_store(&s.total_errors, 0);
    atomic_store(&s.done, 0);

    pthread_t *threads = calloc((size_t)concurrency, sizeof(pthread_t));
    if (!threads) {
        perror("calloc");
        return 1;
    }

    uint64_t t0 = now_ns();
    for (int i = 0; i < concurrency; i++) {
        pthread_create(&threads[i], NULL, worker, &s);
    }

    sleep((unsigned)s.duration);
    atomic_store(&s.done, 1);

    for (int i = 0; i < concurrency; i++) {
        pthread_join(threads[i], NULL);
    }
    uint64_t t1 = now_ns();

    double elapsed = (double)(t1 - t0) / 1e9;
    unsigned long long ops = atomic_load(&s.total_ops);
    unsigned long long bytes = atomic_load(&s.total_bytes);
    unsigned long long errors = atomic_load(&s.total_errors);

    double ops_per_sec = ops / elapsed;
    double avg_latency_us = ops > 0 ? (elapsed / (ops / (double)concurrency)) * 1e6 : 0.0;

    printf("mode=http host=%s port=%d concurrency=%d duration=%.2fs keepalive=%d path=%s\n",
           s.host, s.port, concurrency, elapsed, s.keepalive ? 1 : 0, s.path);
    printf("total_ops=%llu\n", ops);
    printf("ops_per_sec=%.0f\n", ops_per_sec);
    printf("bytes=%llu\n", bytes);
    printf("errors=%llu\n", errors);
    printf("avg_latency_us=%.2f\n", avg_latency_us);

    free(threads);
    free(s.request);
    return 0;
}
