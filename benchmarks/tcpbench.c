// tcpbench — high-performance TCP load generator for the proxy benchmarks.
//
// Three modes:
//
//   ./tcpbench rr -h 127.0.0.1 -p 25432 -c 50 -d 10 -s 1024
//     Request/response mode: opens C persistent connections and loops
//     send(payload) / recv(payload) for D seconds, reporting total ops/sec
//     and average per-op latency. Measures forwarding hot path throughput.
//
//   ./tcpbench rate -h 127.0.0.1 -p 25432 -c 100 -d 10
//     Connection rate mode: C worker threads, each opening a fresh TCP
//     connection, doing the postgres handshake, reading one response, and
//     closing. Reports total new-connections/sec. Measures accept path.
//
//   ./tcpbench hold -h 127.0.0.1 -p 25432 -c 10000 -d 30
//     Hold mode: opens C concurrent connections (each its own thread),
//     performs the initial postgres handshake, then parks idle until the
//     duration expires. Purpose: load generator for the RAM-per-connection
//     scenario — we need to open and keep connections without traffic so
//     the proxy's RSS can be sampled at different connection counts.
//
// Each worker thread uses blocking IO with its own socket. N threads =
// true kernel-level parallelism (unlike coroutine-based clients which
// serialize through one event loop).
//
// Compile:
//   gcc -O2 -pthread -o tcpbench tcpbench.c
//
// Expected to saturate the proxy far beyond what PHP bench clients can.

#define _GNU_SOURCE
#include <arpa/inet.h>
#include <errno.h>
#include <netdb.h>
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

// Postgres startup message: protocol version 3.0 + user/database keys
static const unsigned char PG_STARTUP[] = {
    0x00, 0x00, 0x00, 0x26, // length = 38
    0x00, 0x03, 0x00, 0x00, // protocol 3.0
    'u', 's', 'e', 'r', 0, 'p', 'o', 's', 't', 'g', 'r', 'e', 's', 0,
    'd', 'a', 't', 'a', 'b', 'a', 's', 'e', 0,
    'd', 'b', '-', 'a', 'b', 'c', '1', '2', '3', 0,
    0
};

typedef struct {
    const char *host;
    int port;
    int duration;
    int payload_size;
    char *payload;
    atomic_ullong total_ops;
    atomic_ullong total_bytes;
    atomic_ullong total_errors;
    atomic_ullong held_conns;
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
    inet_pton(AF_INET, host, &addr.sin_addr);

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
        left -= n;
    }
    return len;
}

static ssize_t recv_all(int fd, void *buf, size_t len) {
    char *p = buf;
    size_t left = len;
    while (left > 0) {
        ssize_t n = recv(fd, p, left, 0);
        if (n <= 0) return -1;
        p += n;
        left -= n;
    }
    return len;
}

// Request/response worker: persistent connection, tight loop.
static void *rr_worker(void *arg) {
    shared_t *s = arg;

    int fd = connect_to(s->host, s->port);
    if (fd < 0) return NULL;

    // Handshake so the proxy routes us.
    if (send_all(fd, PG_STARTUP, sizeof(PG_STARTUP)) < 0) {
        close(fd);
        return NULL;
    }
    char hs[4096];
    ssize_t hs_n = recv(fd, hs, sizeof(hs), 0);
    if (hs_n <= 0) {
        close(fd);
        return NULL;
    }

    char *recv_buf = malloc(s->payload_size);
    if (!recv_buf) {
        close(fd);
        return NULL;
    }

    unsigned long long ops = 0;
    unsigned long long bytes = 0;

    while (!atomic_load_explicit(&s->done, memory_order_relaxed)) {
        if (send_all(fd, s->payload, s->payload_size) < 0) break;
        if (recv_all(fd, recv_buf, s->payload_size) < 0) break;
        ops++;
        bytes += s->payload_size;
    }

    atomic_fetch_add_explicit(&s->total_ops, ops, memory_order_relaxed);
    atomic_fetch_add_explicit(&s->total_bytes, bytes, memory_order_relaxed);

    free(recv_buf);
    close(fd);
    return NULL;
}

// Connection rate worker: open, handshake, read response, close, repeat.
static void *rate_worker(void *arg) {
    shared_t *s = arg;
    unsigned long long ops = 0;
    char hs[4096];

    while (!atomic_load_explicit(&s->done, memory_order_relaxed)) {
        int fd = connect_to(s->host, s->port);
        if (fd < 0) continue;
        if (send_all(fd, PG_STARTUP, sizeof(PG_STARTUP)) < 0) {
            close(fd);
            continue;
        }
        ssize_t n = recv(fd, hs, sizeof(hs), 0);
        if (n > 0) ops++;
        close(fd);
    }

    atomic_fetch_add_explicit(&s->total_ops, ops, memory_order_relaxed);
    return NULL;
}

// Hold worker: open one connection, handshake, then idle until done flag is set.
// Legacy thread-per-connection variant, kept for small concurrencies. The main
// loop below uses an epoll-based single-threaded path for hold mode when
// concurrency > 1024, which avoids the per-thread stack + VMA tax.
static void *hold_worker(void *arg) {
    shared_t *s = arg;

    int fd = connect_to(s->host, s->port);
    if (fd < 0) {
        atomic_fetch_add_explicit(&s->total_errors, 1, memory_order_relaxed);
        return NULL;
    }

    if (send_all(fd, PG_STARTUP, sizeof(PG_STARTUP)) < 0) {
        atomic_fetch_add_explicit(&s->total_errors, 1, memory_order_relaxed);
        close(fd);
        return NULL;
    }

    char hs[4096];
    ssize_t hs_n = recv(fd, hs, sizeof(hs), 0);
    if (hs_n <= 0) {
        atomic_fetch_add_explicit(&s->total_errors, 1, memory_order_relaxed);
        close(fd);
        return NULL;
    }

    atomic_fetch_add_explicit(&s->held_conns, 1, memory_order_relaxed);

    while (!atomic_load_explicit(&s->done, memory_order_relaxed)) {
        struct timespec ts = { .tv_sec = 0, .tv_nsec = 100 * 1000 * 1000 }; // 100ms
        nanosleep(&ts, NULL);
    }

    close(fd);
    return NULL;
}

// Epoll-based hold: one thread holds N non-blocking sockets through connect
// completion + PG startup handshake + idle wait. Avoids thread-per-conn limits
// (vm.max_map_count, kernel.threads-max) entirely.
//
// Linux-only — falls back to the thread-per-conn worker on other platforms.
#include <fcntl.h>
#include <poll.h>
#ifdef __linux__
#include <sys/epoll.h>
#endif

static int set_nonblock(int fd) {
    int flags = fcntl(fd, F_GETFL, 0);
    if (flags < 0) return -1;
    return fcntl(fd, F_SETFL, flags | O_NONBLOCK);
}

#ifdef __linux__
// Open and handshake one connection: returns fd on success, -1 on failure.
// Non-blocking connect + write PG startup + wait for 1+ byte response.
static int handshake_conn(struct sockaddr *sa, socklen_t sa_len, int timeout_ms) {
    int fd = socket(sa->sa_family, SOCK_STREAM, 0);
    if (fd < 0) return -1;
    int one = 1;
    setsockopt(fd, IPPROTO_TCP, TCP_NODELAY, &one, sizeof(one));
    if (set_nonblock(fd) < 0) { close(fd); return -1; }
    int rc = connect(fd, sa, sa_len);
    if (rc < 0 && errno != EINPROGRESS) { close(fd); return -1; }

    // Wait for connect() to complete (EPOLLOUT).
    struct pollfd pfd = { .fd = fd, .events = POLLOUT };
    rc = poll(&pfd, 1, timeout_ms);
    if (rc <= 0) { close(fd); return -1; }
    int err = 0; socklen_t el = sizeof(err);
    if (getsockopt(fd, SOL_SOCKET, SO_ERROR, &err, &el) < 0 || err != 0) {
        close(fd); return -1;
    }

    // Send PG startup. Non-blocking, but 8 bytes fits trivially in buffer.
    const unsigned char *p = PG_STARTUP;
    size_t left = sizeof(PG_STARTUP);
    while (left > 0) {
        ssize_t w = send(fd, p, left, MSG_NOSIGNAL);
        if (w > 0) { p += w; left -= w; continue; }
        if (w < 0 && errno == EAGAIN) {
            pfd.events = POLLOUT; poll(&pfd, 1, timeout_ms); continue;
        }
        close(fd); return -1;
    }

    // Wait for any response byte.
    pfd.events = POLLIN;
    rc = poll(&pfd, 1, timeout_ms);
    if (rc <= 0) { close(fd); return -1; }
    char buf[64];
    ssize_t r = recv(fd, buf, sizeof(buf), 0);
    if (r <= 0) { close(fd); return -1; }

    return fd;
}

// Batched-handshake worker: each worker thread owns a slice of the total
// concurrency and handshakes those connections sequentially. Once handshakes
// are done, the worker idles until the done flag. This keeps the per-thread
// count low (8-16 workers total) while still holding N sockets each.
typedef struct {
    shared_t *s;
    int slot_first;
    int slot_count;
    struct sockaddr_storage sa;
    socklen_t sa_len;
    int *slot_fds;
} hold_worker_arg;

static void *hold_batch_worker(void *arg) {
    hold_worker_arg *w = arg;
    for (int k = 0; k < w->slot_count; k++) {
        int fd = handshake_conn((struct sockaddr *)&w->sa, w->sa_len, 30000);
        if (fd < 0) {
            atomic_fetch_add_explicit(&w->s->total_errors, 1, memory_order_relaxed);
            w->slot_fds[k] = -1;
        } else {
            w->slot_fds[k] = fd;
            atomic_fetch_add_explicit(&w->s->held_conns, 1, memory_order_relaxed);
        }
    }
    // Each worker holds its slice for `duration` seconds from the moment
    // THIS worker finished handshaking. Good enough — workers finish within
    // milliseconds of each other and we sample RSS via a dedicated loop.
    struct timespec ts = { .tv_sec = w->s->duration, .tv_nsec = 0 };
    nanosleep(&ts, NULL);
    for (int k = 0; k < w->slot_count; k++) if (w->slot_fds[k] >= 0) close(w->slot_fds[k]);
    return NULL;
}

static int hold_epoll(shared_t *s, int concurrency) {
    struct addrinfo hints = {0}, *res = NULL;
    hints.ai_family = AF_UNSPEC;
    hints.ai_socktype = SOCK_STREAM;
    char port_s[16];
    snprintf(port_s, sizeof(port_s), "%d", s->port);
    if (getaddrinfo(s->host, port_s, &hints, &res) != 0 || !res) {
        fprintf(stderr, "getaddrinfo failed\n");
        return 1;
    }

    // Worker pool sized to available CPUs * 4 — small enough to dodge the
    // VMA/threads-max cap, large enough to parallelise handshakes.
    long cpus = sysconf(_SC_NPROCESSORS_ONLN);
    if (cpus < 2) cpus = 2;
    int workers = (int)(cpus * 4);
    if (workers > concurrency) workers = concurrency;
    if (workers < 1) workers = 1;

    int *all_fds = calloc(concurrency, sizeof(int));
    if (!all_fds) { perror("calloc"); return 1; }

    hold_worker_arg *args = calloc(workers, sizeof(*args));
    pthread_t *tids = calloc(workers, sizeof(pthread_t));
    if (!args || !tids) { perror("calloc"); return 1; }

    int per = concurrency / workers;
    int rem = concurrency - per * workers;
    int offset = 0;
    for (int i = 0; i < workers; i++) {
        int count = per + (i < rem ? 1 : 0);
        args[i].s = s;
        args[i].slot_first = offset;
        args[i].slot_count = count;
        memcpy(&args[i].sa, res->ai_addr, res->ai_addrlen);
        args[i].sa_len = res->ai_addrlen;
        args[i].slot_fds = &all_fds[offset];
        offset += count;
        pthread_create(&tids[i], NULL, hold_batch_worker, &args[i]);
    }

    for (int i = 0; i < workers; i++) pthread_join(tids[i], NULL);

    free(all_fds); free(args); free(tids);
    if (res) freeaddrinfo(res);
    return 0;
}
#else
static int hold_epoll(shared_t *s, int concurrency) {
    // Fallback: spawn thread-per-conn workers. Platform lacks epoll.
    pthread_t *threads = calloc(concurrency, sizeof(pthread_t));
    if (!threads) { perror("calloc"); return 1; }
    for (int i = 0; i < concurrency; i++) pthread_create(&threads[i], NULL, hold_worker, s);
    for (int i = 0; i < concurrency; i++) pthread_join(threads[i], NULL);
    free(threads);
    return 0;
}
#endif

static void usage(const char *argv0) {
    fprintf(stderr,
            "usage: %s rr|rate|hold [-h host] [-p port] [-c concurrency] "
            "[-d duration] [-s payload_size]\n",
            argv0);
    exit(1);
}

int main(int argc, char **argv) {
    if (argc < 2) usage(argv[0]);

    const char *mode = argv[1];
    bool is_rr = strcmp(mode, "rr") == 0;
    bool is_rate = strcmp(mode, "rate") == 0;
    bool is_hold = strcmp(mode, "hold") == 0;
    if (!is_rr && !is_rate && !is_hold) usage(argv[0]);

    shared_t s = {
        .host = "127.0.0.1",
        .port = 25432,
        .duration = 10,
        .payload_size = 1024,
    };
    int concurrency = 50;

    for (int i = 2; i < argc; i++) {
        if (strcmp(argv[i], "-h") == 0 && i + 1 < argc) {
            s.host = argv[++i];
        } else if (strcmp(argv[i], "-p") == 0 && i + 1 < argc) {
            s.port = atoi(argv[++i]);
        } else if (strcmp(argv[i], "-c") == 0 && i + 1 < argc) {
            concurrency = atoi(argv[++i]);
        } else if (strcmp(argv[i], "-d") == 0 && i + 1 < argc) {
            s.duration = atoi(argv[++i]);
        } else if (strcmp(argv[i], "-s") == 0 && i + 1 < argc) {
            s.payload_size = atoi(argv[++i]);
        } else {
            usage(argv[0]);
        }
    }

    if (is_rr) {
        s.payload = malloc(s.payload_size);
        if (!s.payload) {
            perror("malloc");
            return 1;
        }
        memset(s.payload, 'a', s.payload_size);
    }

    atomic_store(&s.total_ops, 0);
    atomic_store(&s.total_bytes, 0);
    atomic_store(&s.total_errors, 0);
    atomic_store(&s.held_conns, 0);
    atomic_store(&s.done, 0);

    uint64_t t0 = now_ns();

    // Hold mode: use the epoll-based worker pool which avoids the
    // thread-per-connection VMA/threads-max ceiling. Each worker handshakes
    // its slice, holds for `duration` seconds, then closes.
    if (is_hold) {
        hold_epoll(&s, concurrency);

        uint64_t t1 = now_ns();
        double elapsed_s = (t1 - t0) / 1e9;
        long long held = (long long)atomic_load(&s.held_conns);
        long long errors = (long long)atomic_load(&s.total_errors);
        printf("mode=hold host=%s:%d concurrency=%d duration=%.2fs\n",
               s.host, s.port, concurrency, elapsed_s);
        printf("total_conns=%lld\n", held);
        printf("held_seconds=%.2f\n", elapsed_s);
        printf("errors=%lld\n", errors);
        if (s.payload) free(s.payload);
        return 0;
    }

    pthread_t *threads = calloc(concurrency, sizeof(pthread_t));
    if (!threads) {
        perror("calloc");
        return 1;
    }

    void *(*worker_fn)(void *) = is_rr ? rr_worker : rate_worker;

    for (int i = 0; i < concurrency; i++) {
        pthread_create(&threads[i], NULL, worker_fn, &s);
    }

    sleep(s.duration);
    atomic_store(&s.done, 1);

    for (int i = 0; i < concurrency; i++) {
        pthread_join(threads[i], NULL);
    }
    uint64_t t1 = now_ns();

    double elapsed = (t1 - t0) / 1e9;
    unsigned long long ops = atomic_load(&s.total_ops);
    unsigned long long bytes = atomic_load(&s.total_bytes);
    unsigned long long errors = atomic_load(&s.total_errors);
    unsigned long long held = atomic_load(&s.held_conns);

    printf("mode=%s host=%s:%d concurrency=%d duration=%.2fs\n",
           mode, s.host, s.port, concurrency, elapsed);

    if (is_hold) {
        printf("total_conns=%llu\n", held);
        printf("held_seconds=%.2f\n", elapsed);
        printf("errors=%llu\n", errors);
    } else {
        printf("total_ops=%llu\n", ops);
        printf("ops_per_sec=%.0f\n", ops / elapsed);
        printf("errors=%llu\n", errors);
    }
    if (is_rr) {
        printf("bytes=%llu\n", bytes);
        printf("throughput_gbps=%.3f\n", (double)bytes / elapsed / (1024.0 * 1024.0 * 1024.0));
        printf("avg_latency_us=%.2f\n", (elapsed / (ops / (double)concurrency)) * 1e6);
    }

    free(threads);
    if (s.payload) free(s.payload);
    return 0;
}
