# Multi-stage build for the Rust port of utopia-php/proxy.
#
# Stage 1 (bpf-builder): compiles the BPF program relay.bpf.o that the
#   runtime image loads via the crate's sockmap module when TCP_SOCKMAP_ENABLED=1.
#   Identical output to the PHP image — same relay.bpf.c / Makefile are reused.
#
# Stage 2 (builder):   cargo build --release --bin proxy on a rust:alpine image.
# Stage 3 (runtime):   slim alpine with libbpf runtime, jemalloc, and the
#                      single proxy binary.
#
# Running:
#
#   docker build -t utopia/proxy-rs -f rust/Dockerfile .
#
#   # TCP proxy with fixed backend (default):
#   docker run --rm --network=host \
#     -e TCP_BACKEND_ENDPOINT=10.0.0.2:5432 \
#     utopia/proxy-rs
#
#   # HTTP proxy:
#   docker run --rm --network=host \
#     -e HTTP_BACKEND_ENDPOINT=10.0.0.2:5678 \
#     utopia/proxy-rs http
#
#   # TCP proxy with sockmap zero-copy relay:
#   docker run --rm \
#     --network=host \
#     --cap-add=BPF --cap-add=NET_ADMIN --cap-add=SYS_RESOURCE \
#     -e TCP_BACKEND_ENDPOINT=10.126.0.2:5432 \
#     -e TCP_SKIP_VALIDATION=1 \
#     -e TCP_SOCKMAP_ENABLED=1 \
#     utopia/proxy-rs
#
# Capabilities required for sockmap:
#   - CAP_BPF          load BPF program + create sockmap
#   - CAP_NET_ADMIN    attach BPF program to sockmap (BPF_PROG_ATTACH)
#   - CAP_SYS_RESOURCE raise RLIMIT_MEMLOCK for BPF map memory
#
# --network=host is recommended so SO_REUSEPORT, sockmap, and kernel
# socket tuning work against the host's real TCP stack.
#
# Runtime tuning (measured in benchmarks/harness/results):
#   --ulimit nofile=1048576:1048576
#   --sysctl net.core.somaxconn=65535
#   --sysctl net.ipv4.tcp_tw_reuse=1
#   --sysctl net.ipv4.ip_local_port_range="1024 65535"
#   --sysctl net.ipv4.tcp_fin_timeout=15
#
# Host-side kernel tuning (apply on the Docker host, not in-container):
#   sysctl -w fs.file-max=2097152
#   sysctl -w vm.max_map_count=1048576
#   sysctl -w net.core.netdev_max_backlog=32768
#   sysctl -w net.ipv4.tcp_max_syn_backlog=32768
# See benchmarks/setup.sh for the full tuning set.

# ------------- stage 1: build the BPF object -------------
FROM alpine:3.20 AS bpf-builder

RUN apk add --no-cache clang llvm libbpf-dev linux-headers make gcc musl-dev

WORKDIR /build
COPY src/Sockmap/relay.bpf.c ./

RUN clang -O2 -g -target bpf \
        -D__TARGET_ARCH_x86 \
        -I/usr/include \
        -c relay.bpf.c -o relay.bpf.o && \
    ls -la relay.bpf.o

# ------------- stage 2: build the Rust binary -------------
FROM rust:1.82-alpine AS builder

RUN apk add --no-cache musl-dev pkgconfig openssl-dev openssl-libs-static \
    libbpf-dev clang-libs linux-headers build-base

WORKDIR /src

# Cache dependencies: copy only manifests first, dummy-build, then real source.
COPY rust/Cargo.toml rust/Cargo.lock ./
COPY rust/crates/utopia-proxy/Cargo.toml crates/utopia-proxy/Cargo.toml
COPY rust/bins/proxy/Cargo.toml bins/proxy/Cargo.toml
RUN mkdir -p crates/utopia-proxy/src bins/proxy/src && \
    echo "fn main(){}" > bins/proxy/src/main.rs && \
    echo "" > crates/utopia-proxy/src/lib.rs && \
    cargo build --release --bin proxy || true

# Now the real sources.
COPY rust/ ./
RUN touch crates/utopia-proxy/src/lib.rs bins/proxy/src/main.rs && \
    cargo build --release --bin proxy && \
    strip target/release/proxy

# ------------- stage 3: runtime -------------
FROM alpine:3.20

RUN apk add --no-cache \
    libbpf \
    jemalloc \
    ca-certificates \
    tini \
    && rm -rf /var/cache/apk/*

WORKDIR /opt/proxy

COPY --from=builder /src/target/release/proxy /opt/proxy/proxy
COPY --from=bpf-builder /build/relay.bpf.o /opt/proxy/relay.bpf.o

# jemalloc as the allocator for the entire process.
ENV LD_PRELOAD=/usr/lib/libjemalloc.so.2

# Default sockmap object path -- referenced when TCP_SOCKMAP_ENABLED=1.
ENV TCP_SOCKMAP_BPF_OBJECT=/opt/proxy/relay.bpf.o

RUN addgroup -S app && adduser -S -G app app && \
    chown -R app:app /opt/proxy
USER app

EXPOSE 8080 8081 25 5432 3306

ENTRYPOINT ["/sbin/tini", "--", "/opt/proxy/proxy"]
CMD ["tcp"]
