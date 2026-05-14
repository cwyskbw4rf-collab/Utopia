#!/usr/bin/env bash
# http_rps.sh -- HTTP sustained RPS per concurrency level.
#
# For each concurrency level, runs httpbench -k for $duration seconds while a
# background sampler tracks peak RSS. Results go into one CSV row per level.
#
# Usage: http_rps.sh <impl> <csv_path>

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HARNESS_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

# shellcheck source=../lib.sh
source "${HARNESS_DIR}/lib.sh"

impl="${1:?impl required}"
csv="${2:?csv path required}"
duration="${DURATION:-30}"

CONCURRENCIES=(500 2000 5000)

httpbench_local="${BENCHMARKS_DIR}/httpbench/httpbench"
if [[ -z "${LOAD_HOST:-}" && ! -x "${httpbench_local}" ]]; then
    echo "[http_rps] building httpbench"
    (cd "${BENCHMARKS_DIR}/httpbench" && make)
fi

echo "[http_rps] impl=${impl} -- starting backend + proxy"
load_target "${impl}" http || exit 1
backend_pid="$(start_backend http)"
proxy_pid="$(start_proxy "${impl}" http)"

trap 'stop_proxy "${proxy_pid}"; stop_backend "${backend_pid}"; remote_cleanup' EXIT

port="$(proxy_port http)"
target_host="$(proxy_connect_host)"
sleep 2

for concurrency in "${CONCURRENCIES[@]}"; do
    echo "[http_rps] concurrency=${concurrency}"
    out="${PROXY_LOG_DIR}/http_rps_${impl}_${concurrency}.out"
    peak_file="${PROXY_LOG_DIR}/http_rps_${impl}_${concurrency}.peak"

    peak_rss_sampler "${proxy_pid}" "${duration}" "${peak_file}" &
    sampler_pid=$!
    register_pid "${sampler_pid}"

    if [[ -n "${LOAD_HOST:-}" || -n "${LOAD_HOSTS:-}" ]]; then
        remote_run_load httpbench "-h ${target_host} -p ${port} -c ${concurrency} -d ${duration} -k" \
            >"${out}" 2>&1
    else
        "${httpbench_local}" -h "${target_host}" -p "${port}" \
            -c "${concurrency}" -d "${duration}" -k \
            >"${out}" 2>&1
    fi

    wait "${sampler_pid}" 2>/dev/null || true
    peak_rss="$(cat "${peak_file}" 2>/dev/null || echo 0)"
    peak_rss="${peak_rss:-0}"

    ops_per_sec="$(parse_kv_output "${out}" ops_per_sec)"
    avg_latency_us="$(parse_kv_output "${out}" avg_latency_us)"
    ops_per_sec="${ops_per_sec:-0}"
    avg_latency_us="${avg_latency_us:-0}"

    ts="$(date +%s)"
    ensure_csv_header "${csv}"
    record_csv "${csv}" \
        "${ts}" "${impl}" "http_rps" "${concurrency}" "${duration}" \
        "${ops_per_sec}" "0" "${avg_latency_us}" "${peak_rss}" "0"

    echo "[http_rps] concurrency=${concurrency} ops/s=${ops_per_sec} latency_us=${avg_latency_us} peak_rss=${peak_rss}KB"
done

stop_proxy "${proxy_pid}"
stop_backend "${backend_pid}"
trap - EXIT

echo "[http_rps] done"
