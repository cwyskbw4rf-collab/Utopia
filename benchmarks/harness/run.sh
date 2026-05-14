#!/usr/bin/env bash
# run.sh -- top-level orchestrator for the proxy benchmark harness.
#
# For each (impl, scenario) pair, sources the target env file, starts a clean
# proxy + backend, runs the scenario, stops everything, and moves on. Results
# accumulate in a single CSV. A markdown report is generated at the end.
#
# Flags:
#   --impl {php|rust|both}           default: both
#   --scenario {ram|rps|conn-rate|all}  default: all
#   --duration SEC                   default: 30
#   --output-dir DIR                 default: benchmarks/harness/results
#   --proxy-host HOST                run proxy on HOST via SSH (e.g. root@1.2.3.4)
#   --load-host  HOST                run load generator on HOST via SSH
#
# Remote hosts can also be set via the PROXY_HOST / LOAD_HOST environment
# variables. When set, helpers in remote.sh dispatch over SSH.
#
# Implementations are run strictly sequentially -- we never run PHP and Rust
# simultaneously, so their ports may overlap in the env files.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

IMPL="both"
SCENARIO="all"
DURATION=30
OUTPUT_DIR="${SCRIPT_DIR}/results"

while (( $# > 0 )); do
    case "$1" in
        --impl)       IMPL="$2"; shift 2 ;;
        --scenario)   SCENARIO="$2"; shift 2 ;;
        --duration)   DURATION="$2"; shift 2 ;;
        --output-dir) OUTPUT_DIR="$2"; shift 2 ;;
        --proxy-host)         export PROXY_HOST="$2"; shift 2 ;;
        --proxy-connect-host) export PROXY_CONNECT_HOST="$2"; shift 2 ;;
        --load-host)          export LOAD_HOST="$2"; shift 2 ;;
        --load-hosts)         export LOAD_HOSTS="$2"; shift 2 ;;
        -h|--help)
            sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "unknown flag: $1" >&2
            exit 2
            ;;
    esac
done

if is_remote_mode; then
    echo "[run] remote mode: proxy_host=${PROXY_HOST:-(local)} load_host=${LOAD_HOST:-(local)}"
    if ! remote_check_tools; then
        echo "[run] run deploy.sh first" >&2
        exit 3
    fi
fi

case "${IMPL}" in
    php|rust|both) ;;
    *) echo "--impl must be php|rust|both" >&2; exit 2 ;;
esac

case "${SCENARIO}" in
    ram|rps|conn-rate|all) ;;
    *) echo "--scenario must be ram|rps|conn-rate|all" >&2; exit 2 ;;
esac

mkdir -p "${OUTPUT_DIR}"

CSV="${OUTPUT_DIR}/$(date +%s).csv"
ensure_csv_header "${CSV}"

export DURATION
export PROXY_LOG_DIR="${OUTPUT_DIR}/logs"
mkdir -p "${PROXY_LOG_DIR}"

check_kernel || true

cleanup() {
    kill_all
}
trap cleanup EXIT INT TERM

IMPLS=()
if [[ "${IMPL}" == "both" ]]; then
    IMPLS=(php rust)
else
    IMPLS=("${IMPL}")
fi

SCENARIOS=()
case "${SCENARIO}" in
    ram)       SCENARIOS=(ram) ;;
    rps)       SCENARIOS=(rps) ;;
    conn-rate) SCENARIOS=(conn-rate) ;;
    all)       SCENARIOS=(ram rps conn-rate) ;;
esac

run_scenario() {
    local impl="$1" sc="$2"
    local script=""
    case "${sc}" in
        ram)       script="${SCRIPT_DIR}/scenarios/ram_per_connection.sh" ;;
        rps)       script="${SCRIPT_DIR}/scenarios/http_rps.sh" ;;
        conn-rate) script="${SCRIPT_DIR}/scenarios/tcp_conn_rate.sh" ;;
    esac
    echo "=== ${impl} / ${sc} ==="
    DURATION="${DURATION}" bash "${script}" "${impl}" "${CSV}"
    # Kill any PIDs the scenario registered before moving on.
    kill_all
    sleep 2
}

for impl in "${IMPLS[@]}"; do
    for sc in "${SCENARIOS[@]}"; do
        run_scenario "${impl}" "${sc}"
    done
done

REPORT="${OUTPUT_DIR}/report.md"
python3 "${SCRIPT_DIR}/report.py" --results-dir "${OUTPUT_DIR}" --output "${REPORT}"

echo
echo "Results CSV: ${CSV}"
echo "Report:      ${REPORT}"
