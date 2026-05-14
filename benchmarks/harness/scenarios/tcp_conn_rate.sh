#!/usr/bin/env bash
# tcp_conn_rate.sh -- TCP accept-path throughput per concurrency level.
#
# Uses `tcpbench rate` which opens/handshakes/closes in a tight loop across
# threads. Background sampler tracks peak proxy RSS.
#
# Usage: tcp_conn_rate.sh <impl> <csv_path>

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HARNESS_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

# shellcheck source=../lib.sh
source "${HARNESS_DIR}/lib.sh"

impl="${1:?impl required}"
csv="${2:?csv path required}"
duration="${DURATION:-30}"

CONCURRENCIES=(50 200 500)

echo "[tcp_conn_rate] impl=${impl} -- starting backend + proxy"
load_target "${impl}" tcp || exit 1
backend_pid="$(start_backend tcp)"
proxy_pid="$(start_proxy "${impl}" tcp)"

trap 'stop_proxy "${proxy_pid}"; stop_backend "${backend_pid}"; remote_cleanup' EXIT

port="$(proxy_port tcp)"
target_host="$(proxy_connect_host)"
sleep 2

for concurrency in "${CONCURRENCIES[@]}"; do
    echo "[tcp_conn_rate] concurrency=${concurrency}"
    out="${PROXY_LOG_DIR}/tcp_rate_${impl}_${concurrency}.out"
    peak_file="${PROXY_LOG_DIR}/tcp_rate_${impl}_${concurrency}.peak"

    peak_rss_sampler "${proxy_pid}" "${duration}" "${peak_file}" &
    sampler_pid=$!
    register_pid "${sampler_pid}"

    if [[ -n "${LOAD_HOST:-}" || -n "${LOAD_HOSTS:-}" ]]; then
        remote_run_load tcpbench "rate -h ${target_host} -p ${port} -c ${concurrency} -d ${duration}" \
            >"${out}" 2>&1
    else
        "${BENCHMARKS_DIR}/tcpbench" rate \
            -h "${target_host}" -p "${port}" \
            -c "${concurrency}" -d "${duration}" \
            >"${out}" 2>&1
    fi

    wait "${sampler_pid}" 2>/dev/null || true
    peak_rss="$(cat "${peak_file}" 2>/dev/null || echo 0)"
    peak_rss="${peak_rss:-0}"

    ops_per_sec="$(parse_kv_output "${out}" ops_per_sec)"
    ops_per_sec="${ops_per_sec:-0}"

    ts="$(date +%s)"
    ensure_csv_header "${csv}"
    record_csv "${csv}" \
        "${ts}" "${impl}" "tcp_conn_rate" "${concurrency}" "${duration}" \
        "0" "${ops_per_sec}" "0" "${peak_rss}" "0"

    echo "[tcp_conn_rate] concurrency=${concurrency} conn/s=${ops_per_sec} peak_rss=${peak_rss}KB"
done

stop_proxy "${proxy_pid}"
stop_backend "${backend_pid}"
trap - EXIT

echo "[tcp_conn_rate] done"
