#!/usr/bin/env bash
# deploy.sh -- prepare remote hosts for the benchmark harness.
#
# Uploads the project tree to both PROXY_HOST and LOAD_HOST (if set),
# installs dependencies, builds the Rust release binary and the C load
# generators on the remote hosts, and runs a smoke check.
#
# Usage:
#   PROXY_HOST=root@1.2.3.4 LOAD_HOST=root@5.6.7.8 ./deploy.sh
#   PROXY_HOST=... LOAD_HOST=... ./deploy.sh --skip-build   # rsync only
#   PROXY_HOST=... LOAD_HOST=... ./deploy.sh --install-deps # apt-get too
#
# Assumes:
#   - Debian/Ubuntu targets (uses apt-get when --install-deps is passed)
#   - Passwordless root SSH (or the user in PROXY_HOST/LOAD_HOST can sudo freely)
#   - Local machine has rsync and ssh

set -euo pipefail

HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROXY_ROOT="$(cd "${HARNESS_DIR}/../.." && pwd)"

# shellcheck source=remote.sh
source "${HARNESS_DIR}/remote.sh"

INSTALL_DEPS=0
SKIP_BUILD=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --install-deps) INSTALL_DEPS=1; shift ;;
        --skip-build)   SKIP_BUILD=1;   shift ;;
        -h|--help)
            sed -n '1,20p' "$0"
            exit 0
            ;;
        *)
            echo "unknown arg: $1" >&2
            exit 1
            ;;
    esac
done

if ! is_remote_mode; then
    echo "[deploy] neither PROXY_HOST nor LOAD_HOST is set; nothing to do" >&2
    exit 1
fi

echo "[deploy] project root : ${PROXY_ROOT}"
echo "[deploy] proxy host   : ${PROXY_HOST:-(local)}"
echo "[deploy] load host    : ${LOAD_HOST:-(local)}"
echo "[deploy] proxy dir    : ${PROXY_REMOTE_DIR}"
echo "[deploy] load dir     : ${LOAD_REMOTE_DIR}"

# Honor --install-deps: install php, swoole ext, rust toolchain, gcc, make.
install_deps() {
    local host="$1"
    echo "[deploy] installing dependencies on ${host}"
    ssh_run "${host}" bash -s <<'DEPS'
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

if command -v apt-get >/dev/null; then
    apt-get update -qq
    apt-get install -y -qq --no-install-recommends \
        build-essential pkg-config git curl ca-certificates \
        php-cli php-dev php-redis composer \
        libssl-dev zlib1g-dev libbpf-dev clang llvm linux-headers-$(uname -r 2>/dev/null || echo generic) || true

    # Swoole >= 6 from PECL (php-swoole distro package is usually too old)
    if ! php -m | grep -qi '^swoole$'; then
        printf '' | pecl install -f swoole 2>&1 | tail -5 || true
        php_ini="$(php --ini | awk -F: '/Loaded Configuration File/ { gsub(/^[[:space:]]+/,"",$2); print $2 }')"
        if [[ -n "${php_ini}" && -f "${php_ini}" ]] && ! grep -q 'extension=swoole' "${php_ini}"; then
            echo "extension=swoole.so" >> "${php_ini}"
        fi
    fi
fi

# Rust toolchain (rustup).
if ! command -v cargo >/dev/null; then
    curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh -s -- -y --default-toolchain stable
fi

true
DEPS
}

deploy_tree() {
    local host="$1" dest="$2"
    echo "[deploy] rsync -> ${host}:${dest}"
    ssh_run "${host}" "mkdir -p ${dest}"
    rsync_to "${host}" "${dest}"
}

build_proxy_host() {
    local host="$1"
    echo "[deploy] building on proxy host ${host}"
    ssh_run "${host}" bash -s <<EOF
set -euo pipefail
source ~/.cargo/env 2>/dev/null || true

# Rust release build (workspace lives under rust/)
(cd ${PROXY_REMOTE_DIR}/rust && cargo build --release --bin proxy)

# PHP composer install (vendored deps)
if command -v composer >/dev/null; then
    (cd ${PROXY_REMOTE_DIR} && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader) || true
fi

# Compile C load generators (also useful on proxy host for local smoke)
(cd ${PROXY_REMOTE_DIR}/benchmarks && gcc -O2 -Wall -Wextra -pthread -o tcpbench tcpbench.c) || true
(cd ${PROXY_REMOTE_DIR}/benchmarks/httpbench && make) || true
EOF
}

build_load_host() {
    local host="$1"
    echo "[deploy] building load generators on ${host}"
    ssh_run "${host}" bash -s <<EOF
set -euo pipefail
cd ${LOAD_REMOTE_DIR}/benchmarks
gcc -O2 -Wall -Wextra -pthread -o tcpbench tcpbench.c
(cd httpbench && make)
EOF
}

smoke_check() {
    local host="$1" kind="$2"
    echo "[deploy] smoke-check ${kind} on ${host}"
    case "${kind}" in
        proxy)
            ssh_run "${host}" "test -x ${PROXY_REMOTE_DIR}/rust/target/release/proxy && \
                               php -v >/dev/null 2>&1 && \
                               (php -m | grep -qi swoole && echo '  swoole: ok' || echo '  swoole: MISSING — PHP runs will fail')"
            ;;
        load)
            ssh_run "${host}" "test -x ${LOAD_REMOTE_DIR}/benchmarks/tcpbench && \
                               test -x ${LOAD_REMOTE_DIR}/benchmarks/httpbench/httpbench && \
                               echo '  load generators: ok'"
            ;;
    esac
}

main() {
    if [[ -n "${PROXY_HOST:-}" ]]; then
        if (( INSTALL_DEPS )); then install_deps "${PROXY_HOST}"; fi
        deploy_tree "${PROXY_HOST}" "${PROXY_REMOTE_DIR}"
        if (( ! SKIP_BUILD )); then build_proxy_host "${PROXY_HOST}"; fi
        smoke_check "${PROXY_HOST}" proxy
    fi

    if [[ -n "${LOAD_HOST:-}" && "${LOAD_HOST}" != "${PROXY_HOST:-}" ]]; then
        if (( INSTALL_DEPS )); then install_deps "${LOAD_HOST}"; fi
        deploy_tree "${LOAD_HOST}" "${LOAD_REMOTE_DIR}"
        if (( ! SKIP_BUILD )); then build_load_host "${LOAD_HOST}"; fi
        smoke_check "${LOAD_HOST}" load
    fi

    echo "[deploy] done."
}

main
