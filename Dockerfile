# Multi-stage build for the utopia-php/proxy.
#
# Stage 1 (bpf-builder): compiles the BPF program relay.bpf.o that the
#   runtime image loads via src/Sockmap/Loader.php. We use clang in a
#   throwaway image so the runtime doesn't carry the LLVM toolchain.
#
# Stage 2 (runtime): slim PHP 8.4 Alpine image with swoole + ffi + libbpf
#   + libjemalloc installed. The sockmap BPF object from stage 1 is
#   copied in at /opt/proxy/relay.bpf.o and referenced by TCP_SOCKMAP_BPF_OBJECT.
#
# Running:
#
#   docker build -t utopia/proxy .
#
#   # TCP proxy with fixed backend (default):
#   docker run --rm --network=host \
#     -e TCP_BACKEND_ENDPOINT=10.0.0.2:5432 \
#     utopia/proxy
#
#   # HTTP proxy:
#   docker run --rm --network=host \
#     -e HTTP_BACKEND_ENDPOINT=10.0.0.2:5678 \
#     utopia/proxy http
#
#   # Custom resolver (mount a PHP file that returns a Resolver instance):
#   docker run --rm --network=host \
#     -v ./resolver.php:/etc/utopia-proxy/resolver.php:ro \
#     utopia/proxy tcp
#
#   # Custom resolver with extra Composer dependencies:
#   docker run --rm --network=host \
#     -v ./my-proxy:/app:ro \
#     -e PROXY_RESOLVER=/app/resolver.php \
#     utopia/proxy tcp
#
#   # TCP proxy with sockmap zero-copy relay:
#   docker run --rm \
#     --network=host \
#     --cap-add=BPF --cap-add=NET_ADMIN --cap-add=SYS_RESOURCE \
#     -e TCP_BACKEND_ENDPOINT=10.126.0.2:5432 \
#     -e TCP_SKIP_VALIDATION=1 \
#     -e TCP_SOCKMAP_ENABLED=1 \
#     utopia/proxy
#
# Capabilities required for sockmap:
#   - CAP_BPF         load BPF program + create sockmap
#   - CAP_NET_ADMIN   attach BPF program to sockmap (BPF_PROG_ATTACH)
#   - CAP_SYS_RESOURCE  raise RLIMIT_MEMLOCK for BPF map memory
#
# --network=host is recommended so SO_REUSEPORT, sockmap, and kernel
# socket tuning work against the host's real TCP stack rather than
# Docker's userspace bridge.
#
# Runtime tuning (measured in benchmarks/harness/results):
#   --ulimit nofile=1048576:1048576              # 1M file descriptors
#   --sysctl net.core.somaxconn=65535            # accept queue
#   --sysctl net.ipv4.tcp_tw_reuse=1             # recycle TIME_WAIT faster
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

# ------------- stage 2: runtime -------------
FROM php:8.4.18-cli-alpine3.23

RUN apk update && apk upgrade && apk add --no-cache \
    autoconf \
    g++ \
    make \
    linux-headers \
    libstdc++ \
    libffi-dev \
    libbpf \
    jemalloc \
    brotli-dev \
    libzip-dev \
    openssl-dev \
    && rm -rf /var/cache/apk/*

RUN docker-php-ext-configure ffi --with-ffi && \
    docker-php-ext-install \
        ffi \
        pcntl \
        sockets \
        zip

RUN pecl channel-update pecl.php.net && \
    pecl install swoole && \
    docker-php-ext-enable swoole

RUN pecl install redis && \
    docker-php-ext-enable redis

# Enable tracing JIT for the long-lived workers.
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.enable_cli=1'; \
    echo 'opcache.jit=tracing'; \
    echo 'opcache.jit_buffer_size=128M'; \
    echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/99-opcache.ini

WORKDIR /opt/proxy

COPY composer.json ./
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
RUN composer install \
    --no-dev \
    --optimize-autoloader

COPY . .

# Install the precompiled BPF object at a stable path so the proxy can
# find it via TCP_SOCKMAP_BPF_OBJECT.
COPY --from=bpf-builder /build/relay.bpf.o /opt/proxy/relay.bpf.o

# jemalloc as the allocator for the entire process.
ENV LD_PRELOAD=/usr/lib/libjemalloc.so.2

# Default sockmap object path — referenced when TCP_SOCKMAP_ENABLED=1.
ENV TCP_SOCKMAP_BPF_OBJECT=/opt/proxy/relay.bpf.o

# Default resolver mount point. Override with PROXY_RESOLVER env.
# Mount a PHP file that returns a Utopia\Proxy\Resolver instance.
ENV PROXY_RESOLVER=/etc/utopia-proxy/resolver.php

RUN addgroup -S app && adduser -S -G app app && \
    chown -R app:app /opt/proxy
USER app

EXPOSE 8080 8081 25 5432 3306

ENTRYPOINT ["php", "/opt/proxy/bin/proxy"]
CMD ["tcp"]
