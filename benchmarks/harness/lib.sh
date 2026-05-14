#!/usr/bin/env bash
# lib.sh -- shared helpers for the benchmark harness.
#
# Sourced by run.sh and scenario scripts. Provides:
#   start_proxy, stop_proxy     -- launch/teardown a proxy implementation
#   start_backend, stop_backend -- launch/teardown the reference PHP backend
#   sample_rss                  -- snapshot a process's RSS in KB
#   check_kernel                -- warn about missing kernel tuning
#   record_csv                  -- append a CSV row
#   wait_port                   -- wait for a TCP port to accept connections

# shellcheck disable=SC2034
HARNESS_LIB_LOADED=1

HARNESS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROXY_ROOT="$(cd "${HARNESS_DIR}/../.." && pwd)"
BENCHMARKS_DIR="${PROXY_ROOT}/benchmarks"

: "${PROXY_LOG_DIR:=${HARNESS_DIR}/results/logs}"
mkdir -p "${PROXY_LOG_DIR}"

# shellcheck source=remote.sh
source "${HARNESS_DIR}/remote.sh"

# Track spawned PIDs for cleanup.
HARNESS_PIDS=()

register_pid() {
    HARNESS_PIDS+=("$1")
}

kill_all() {
    local pid
    for pid in "${HARNESS_PIDS[@]:-}"; do
        if [[ -n "${pid}" ]] && kill -0 "${pid}" 2>/dev/null; then
            kill -TERM "${pid}" 2>/dev/null || true
        fi
    done
    sleep 1
    for pid in "${HARNESS_PIDS[@]:-}"; do
        if [[ -n "${pid}" ]] && kill -0 "${pid}" 2>/dev/null; then
            kill -KILL "${pid}" 2>/dev/null || true
        fi
    done
    HARNESS_PIDS=()
}

# wait_port host port timeout_sec -- returns 0 if port accepts, 1 on timeout.
wait_port() {
    local host="$1" port="$2" timeout="${3:-10}"
    local start now
    start=$(date +%s)
    while :; do
        if command -v nc >/dev/null 2>&1; then
            if nc -z -w 1 "${host}" "${port}" 2>/dev/null; then
                return 0
            fi
        else
            # bash /dev/tcp fallback
            if (exec 3<>"/dev/tcp/${host}/${port}") 2>/dev/null; then
                exec 3<&- 3>&-
                return 0
            fi
        fi
        now=$(date +%s)
        if (( now - start >= timeout )); then
            return 1
        fi
        sleep 0.2
    done
}

# load_target impl protocol -- sources targets/${impl}_${protocol}.env.
# Exposes BIN, plus whatever env vars the target file exports.
load_target() {
    local impl="$1" protocol="$2"
    local env_file="${HARNESS_DIR}/targets/${impl}_${protocol}.env"
    if [[ ! -f "${env_file}" ]]; then
        echo "[harness] no target env file: ${env_file}" >&2
        return 1
    fi
    # shellcheck disable=SC1090
    source "${env_file}"
}

# start_proxy impl protocol -- launches the proxy. Prints PID on stdout.
# The BIN variable from the env file is used as the command line (may contain
# multiple words, e.g. "php /path/to/bin/proxy tcp" or "/path/to/rust/proxy tcp").
start_proxy() {
    local impl="$1" protocol="$2"

    if [[ -n "${PROXY_HOST:-}" ]]; then
        remote_start_proxy "${impl}" "${protocol}"
        return $?
    fi

    load_target "${impl}" "${protocol}" || return 1

    if [[ -z "${BIN:-}" ]]; then
        echo "[harness] target ${impl}_${protocol} did not set BIN" >&2
        return 1
    fi

    local log="${PROXY_LOG_DIR}/${impl}_${protocol}.log"
    # shellcheck disable=SC2086
    ${BIN} >"${log}" 2>&1 &
    local pid=$!
    register_pid "${pid}"

    local port
    port="$(proxy_port "${protocol}")"
    if ! wait_port 127.0.0.1 "${port}" 10; then
        echo "[harness] proxy ${impl} ${protocol} did not open port ${port} within 10s (log: ${log})" >&2
        stop_proxy "${pid}"
        return 1
    fi

    echo "${pid}"
}

stop_proxy() {
    local spec="$1"
    if [[ -z "${spec}" ]]; then
        return 0
    fi

    # Remote: spec is "pid:pidfile".
    if [[ -n "${PROXY_HOST:-}" && "${spec}" == *:* ]]; then
        local pid="${spec%%:*}" pidfile="${spec#*:}"
        remote_stop_proxy "${pid}" "${pidfile}"
        return 0
    fi

    local pid="${spec}"
    if kill -0 "${pid}" 2>/dev/null; then
        kill -TERM "${pid}" 2>/dev/null || true
        local waited=0
        while kill -0 "${pid}" 2>/dev/null; do
            sleep 1
            waited=$((waited + 1))
            if (( waited >= 5 )); then
                kill -KILL "${pid}" 2>/dev/null || true
                break
            fi
        done
    fi
}

# proxy_port protocol -- echoes the primary port the proxy listens on,
# derived from env vars set by the target file.
proxy_port() {
    local protocol="$1"
    case "${protocol}" in
        tcp)  echo "${TCP_POSTGRES_PORT:-5432}" ;;
        http) echo "${HTTP_PORT:-8080}" ;;
        smtp) echo "${SMTP_PORT:-25}" ;;
        *)    echo "0" ;;
    esac
}

# start_backend protocol -- launches the reference PHP backend for the protocol.
# Uses fixed local ports (matches TCP_BACKEND_ENDPOINT / HTTP_BACKEND_ENDPOINT
# in the target env files).
start_backend() {
    local protocol="$1"

    if [[ -n "${PROXY_HOST:-}" ]]; then
        remote_start_backend "${protocol}"
        return $?
    fi

    local script port
    case "${protocol}" in
        tcp)
            script="${BENCHMARKS_DIR}/tcp-backend.php"
            port="${BACKEND_TCP_PORT:-15432}"
            export BACKEND_PORT="${port}"
            ;;
        http)
            script="${BENCHMARKS_DIR}/http-backend.php"
            port="${BACKEND_HTTP_PORT:-5678}"
            export BACKEND_PORT="${port}"
            ;;
        *)
            echo "[harness] unsupported backend protocol: ${protocol}" >&2
            return 1
            ;;
    esac

    local log="${PROXY_LOG_DIR}/backend_${protocol}.log"
    php "${script}" >"${log}" 2>&1 &
    local pid=$!
    register_pid "${pid}"

    if ! wait_port 127.0.0.1 "${port}" 10; then
        echo "[harness] backend ${protocol} did not open port ${port} within 10s (log: ${log})" >&2
        stop_proxy "${pid}"
        return 1
    fi

    echo "${pid}"
}

stop_backend() {
    stop_proxy "$1"
}

# sample_rss pid -- echoes RSS in KB, or 0 if the PID has gone away.
sample_rss() {
    local spec="$1"
    if [[ -z "${spec}" ]]; then echo 0; return; fi

    # Remote: spec can be "pid" or "pid:pidfile".
    if [[ -n "${PROXY_HOST:-}" ]]; then
        local pid="${spec%%:*}"
        remote_sample_rss "${pid}"
        return
    fi

    local pid="${spec}"
    if ! kill -0 "${pid}" 2>/dev/null; then
        echo 0
        return
    fi
    local rss
    rss="$(ps -o rss= -p "${pid}" 2>/dev/null | awk '{print $1}')"
    if [[ -z "${rss}" ]]; then
        echo 0
    else
        echo "${rss}"
    fi
}

# median_of_samples n1 n2 n3 ... -- echoes the median.
median_of_samples() {
    local sorted
    sorted="$(printf '%s\n' "$@" | sort -n)"
    local count
    count=$(printf '%s\n' "$@" | wc -l | tr -d ' ')
    local mid=$(( (count + 1) / 2 ))
    printf '%s\n' "${sorted}" | sed -n "${mid}p"
}

# check_kernel -- warn about missing tuning. Non-fatal.
check_kernel() {
    local warnings=0

    if command -v sysctl >/dev/null 2>&1; then
        local somaxconn
        somaxconn="$(sysctl -n net.core.somaxconn 2>/dev/null || echo 0)"
        if [[ -n "${somaxconn}" ]] && (( somaxconn < 32768 )); then
            echo "[harness] warning: net.core.somaxconn=${somaxconn} < 32768 (see ${BENCHMARKS_DIR}/setup.sh)" >&2
            warnings=$((warnings + 1))
        fi
    fi

    local nofile
    nofile="$(ulimit -n 2>/dev/null || echo 0)"
    if [[ "${nofile}" != "unlimited" ]] && (( nofile < 1000000 )); then
        echo "[harness] warning: ulimit -n=${nofile} < 1000000 (see ${BENCHMARKS_DIR}/setup.sh)" >&2
        warnings=$((warnings + 1))
    fi

    if (( warnings > 0 )); then
        echo "[harness] results may be bounded by kernel limits, not the proxy" >&2
    fi
}

# parse_kv_output file key -- extract value for `key=...` from key=value output.
parse_kv_output() {
    local file="$1" key="$2"
    awk -F= -v k="${key}" '$1==k { print $2; exit }' "${file}"
}

# record_csv csv_path field1 field2 ...
record_csv() {
    local csv="$1"
    shift
    local line="" first=1
    local f
    for f in "$@"; do
        if (( first )); then
            line="${f}"
            first=0
        else
            line="${line},${f}"
        fi
    done
    printf '%s\n' "${line}" >>"${csv}"
}

# ensure_csv_header csv_path -- writes the standard CSV header if file is empty.
ensure_csv_header() {
    local csv="$1"
    if [[ ! -s "${csv}" ]]; then
        printf 'timestamp,impl,scenario,concurrency,duration,ops_per_sec,conns_per_sec,avg_latency_us,rss_peak_kb,rss_per_conn_kb\n' >"${csv}"
    fi
}

# peak_rss_sampler pid duration_sec outfile
# Samples RSS once per second for duration_sec and writes the peak KB to outfile.
peak_rss_sampler() {
    local pid="$1" duration="$2" outfile="$3"
    local peak=0 sample i=0
    while (( i < duration )); do
        sample="$(sample_rss "${pid}")"
        if (( sample > peak )); then peak="${sample}"; fi
        sleep 1
        i=$((i + 1))
    done
    printf '%s\n' "${peak}" >"${outfile}"
}
