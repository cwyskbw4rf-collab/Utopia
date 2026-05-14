#!/usr/bin/env bash
# remote.sh -- SSH-based orchestration helpers for the benchmark harness.
#
# Sourced by lib.sh after its localhost defaults are defined. If PROXY_HOST
# and/or LOAD_HOST are set in the environment, the corresponding helpers
# dispatch over SSH instead of running locally.
#
# Environment variables consumed:
#   PROXY_HOST        -- host that runs the proxy under test (e.g. root@1.2.3.4)
#   LOAD_HOST         -- host that runs the load generator (e.g. root@5.6.7.8)
#   PROXY_REMOTE_DIR  -- install dir on PROXY_HOST (default /opt/utopia-proxy)
#   LOAD_REMOTE_DIR   -- install dir on LOAD_HOST  (default /opt/utopia-proxy)
#   SSH_OPTS          -- extra ssh options

: "${PROXY_REMOTE_DIR:=/opt/utopia-proxy}"
: "${LOAD_REMOTE_DIR:=/opt/utopia-proxy}"
: "${SSH_OPTS:=-T -o ConnectTimeout=10 -o StrictHostKeyChecking=accept-new -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -o BatchMode=yes}"

is_remote_mode() {
    [[ -n "${PROXY_HOST:-}" || -n "${LOAD_HOST:-}" || -n "${LOAD_HOSTS:-}" ]]
}

ssh_run() {
    local host="$1"
    shift
    # shellcheck disable=SC2086
    ssh ${SSH_OPTS} "${host}" "$@"
}

ssh_stdout() {
    local host="$1"
    shift
    # shellcheck disable=SC2086
    ssh ${SSH_OPTS} "${host}" "$@"
}

rsync_to() {
    local host="$1" dest="$2"
    if [[ -z "${host}" || -z "${dest}" ]]; then
        return 1
    fi
    # shellcheck disable=SC2086
    rsync -az --delete \
        --exclude ".git/" \
        --exclude "rust/target/" \
        --exclude "benchmarks/harness/results/" \
        --exclude "vendor/" \
        --exclude "*.log" \
        --exclude "node_modules/" \
        -e "ssh ${SSH_OPTS}" \
        "${PROXY_ROOT}/" "${host}:${dest}/"
}

# --- remote proxy lifecycle --------------------------------------------------

remote_start_proxy() {
    local impl="$1" protocol="$2"
    load_target "${impl}" "${protocol}" || return 1
    if [[ -z "${BIN:-}" ]]; then
        echo "[harness] remote target ${impl}_${protocol} did not set BIN" >&2
        return 1
    fi

    local port
    port="$(proxy_port "${protocol}")"

    local env_exports=""
    local var
    while IFS= read -r var; do
        local val="${!var-}"
        local escaped="${val//\'/\'\\\'\'}"
        env_exports+="export ${var}='${escaped}'"$'\n'
    done < <(compgen -v | grep -E '^(PROXY_|TCP_|HTTP_|SMTP_|BACKEND_)')

    local pidfile="/tmp/utopia-proxy-${protocol}.pid"
    local logfile="${PROXY_REMOTE_DIR}/logs/${impl}_${protocol}.log"

    # Launch via a heredoc to sidestep nested-quote hell. `setsid -f` detaches;
    # we rediscover the real PID via `ss` on the listening port below.
    # shellcheck disable=SC2086
    ssh ${SSH_OPTS} "${PROXY_HOST}" bash -s >/dev/null <<REMOTE_EOF
${env_exports}
mkdir -p "${PROXY_REMOTE_DIR}/logs"
cd "${PROXY_REMOTE_DIR}"
rm -f "${pidfile}"
setsid -f bash -c 'exec ${BIN} >"${logfile}" 2>&1 </dev/null'
REMOTE_EOF

    # Poll for the listener; once it's up, grab PID from ss.
    if ! wait_remote_port "${PROXY_HOST}" 127.0.0.1 "${port}" 20; then
        echo "[harness] remote proxy ${impl} ${protocol} did not open 127.0.0.1:${port} on ${PROXY_HOST} within 20s" >&2
        ssh_run "${PROXY_HOST}" "cat ${logfile} 2>/dev/null | tail -40" >&2 || true
        return 1
    fi

    local pid
    pid="$(ssh_stdout "${PROXY_HOST}" "ss -tlnp 'sport = :${port}' 2>/dev/null | grep -oE 'pid=[0-9]+' | head -1 | cut -d= -f2")"
    pid="$(echo "${pid}" | tr -d '[:space:]')"
    if [[ ! "${pid}" =~ ^[0-9]+$ ]]; then
        echo "[harness] remote_start_proxy: could not find PID listening on ${port}" >&2
        return 1
    fi
    ssh_run "${PROXY_HOST}" "echo ${pid} > ${pidfile}" >/dev/null

    HARNESS_REMOTE_PIDS+=("${PROXY_HOST}:${pid}:${pidfile}")
    echo "${pid}:${pidfile}"
}

remote_stop_proxy() {
    local pid="$1" pidfile="${2:-}"
    if [[ -z "${pid}" ]]; then return 0; fi
    # setsid -f puts the process in its own session; kill the whole process group
    # so Swoole workers / backend workers go down too.
    ssh_run "${PROXY_HOST}" \
        "pgid=\$(ps -o pgid= -p ${pid} 2>/dev/null | tr -d ' '); \
         if [ -n \"\$pgid\" ]; then kill -TERM -\$pgid 2>/dev/null || true; \
             for i in 1 2 3 4 5; do kill -0 ${pid} 2>/dev/null || break; sleep 1; done; \
             kill -KILL -\$pgid 2>/dev/null || true; \
         fi; \
         rm -f ${pidfile} 2>/dev/null || true" || true
}

remote_start_backend() {
    local protocol="$1"
    local script port
    case "${protocol}" in
        tcp)
            script="${PROXY_REMOTE_DIR}/benchmarks/tcp-backend.php"
            port="${BACKEND_TCP_PORT:-15432}"
            ;;
        http)
            script="${PROXY_REMOTE_DIR}/benchmarks/http-backend.php"
            port="${BACKEND_HTTP_PORT:-5678}"
            ;;
        *)
            echo "[harness] unsupported remote backend protocol: ${protocol}" >&2
            return 1
            ;;
    esac

    local pidfile="/tmp/utopia-backend-${protocol}.pid"
    local logfile="${PROXY_REMOTE_DIR}/logs/backend_${protocol}.log"

    # Wait for the port to be FREE first (prevents "Address already in use" from
    # lingering state or previous-run zombies we may have missed).
    if ! wait_remote_port_free "${PROXY_HOST}" "${port}" 15; then
        echo "[harness] port ${port} still held on ${PROXY_HOST}; killing anything bound to it" >&2
        ssh_run "${PROXY_HOST}" "fuser -k -TERM ${port}/tcp 2>/dev/null; sleep 1; fuser -k -KILL ${port}/tcp 2>/dev/null" || true
        sleep 1
    fi

    # shellcheck disable=SC2086
    ssh ${SSH_OPTS} "${PROXY_HOST}" bash -s >/dev/null <<REMOTE_EOF
mkdir -p "${PROXY_REMOTE_DIR}/logs"
rm -f "${pidfile}"
ulimit -n 1048576 2>/dev/null || true
export BACKEND_PORT=${port}
setsid -f bash -c 'exec php "${script}" >"${logfile}" 2>&1 </dev/null'
REMOTE_EOF

    if ! wait_remote_port "${PROXY_HOST}" 127.0.0.1 "${port}" 12; then
        echo "[harness] remote backend ${protocol} did not open 127.0.0.1:${port} on ${PROXY_HOST}" >&2
        ssh_run "${PROXY_HOST}" "cat ${logfile} 2>/dev/null | tail -40" >&2 || true
        return 1
    fi

    local pid
    pid="$(ssh_stdout "${PROXY_HOST}" "ss -tlnp 'sport = :${port}' 2>/dev/null | grep -oE 'pid=[0-9]+' | head -1 | cut -d= -f2")"
    pid="$(echo "${pid}" | tr -d '[:space:]')"
    if [[ ! "${pid}" =~ ^[0-9]+$ ]]; then
        echo "[harness] remote backend PID discovery failed" >&2
        return 1
    fi
    ssh_run "${PROXY_HOST}" "echo ${pid} > ${pidfile}" >/dev/null

    HARNESS_REMOTE_PIDS+=("${PROXY_HOST}:${pid}:${pidfile}")
    echo "${pid}:${pidfile}"
}

# wait_remote_port ssh_host host port timeout_sec -- probe a port *on* the remote host.
wait_remote_port() {
    local ssh_host="$1" host="$2" port="$3" timeout="${4:-10}"
    local start now
    start=$(date +%s)
    while :; do
        if ssh_run "${ssh_host}" "nc -z -w 1 ${host} ${port} 2>/dev/null || (exec 3<>/dev/tcp/${host}/${port}) 2>/dev/null" 2>/dev/null; then
            return 0
        fi
        now=$(date +%s)
        if (( now - start >= timeout )); then return 1; fi
        sleep 0.5
    done
}

# wait_remote_port_free ssh_host port timeout_sec -- wait until NO listener is on port.
wait_remote_port_free() {
    local ssh_host="$1" port="$2" timeout="${3:-10}"
    local start now
    start=$(date +%s)
    while :; do
        local holders
        holders="$(ssh_stdout "${ssh_host}" "ss -tlnp 'sport = :${port}' 2>/dev/null | tail -n +2 | wc -l" 2>/dev/null)"
        holders="$(echo "${holders}" | tr -d '[:space:]')"
        if [[ "${holders}" == "0" ]]; then return 0; fi
        now=$(date +%s)
        if (( now - start >= timeout )); then return 1; fi
        sleep 0.5
    done
}

remote_sample_rss() {
    local pid="$1"
    if [[ -z "${pid}" ]]; then echo 0; return; fi
    # Sum RSS across the whole process group so Swoole masters + workers both count.
    local rss
    rss="$(ssh_stdout "${PROXY_HOST}" \
        "pgid=\$(ps -o pgid= -p ${pid} 2>/dev/null | tr -d ' '); \
         if [ -n \"\$pgid\" ]; then \
             ps -eo pgid=,rss= | awk -v p=\"\$pgid\" '\$1==p { s += \$2 } END { print s+0 }'; \
         else echo 0; fi")"
    rss="$(echo "${rss}" | tr -d '[:space:]')"
    if [[ -z "${rss}" ]]; then echo 0; else echo "${rss}"; fi
}

remote_run_load() {
    local tool="$1"
    shift
    local path
    case "${tool}" in
        tcpbench)
            path="${LOAD_REMOTE_DIR}/benchmarks/tcpbench"
            ;;
        httpbench)
            path="${LOAD_REMOTE_DIR}/benchmarks/httpbench/httpbench"
            ;;
        *)
            echo "[harness] unknown load tool: ${tool}" >&2
            return 1
            ;;
    esac

    # Prefer the explicit LOAD_HOST; else use the first entry from LOAD_HOSTS.
    local host="${LOAD_HOST:-}"
    if [[ -z "${host}" && -n "${LOAD_HOSTS:-}" ]]; then
        host="${LOAD_HOSTS%%,*}"
    fi
    if [[ -z "${host}" ]]; then
        echo "[harness] neither LOAD_HOST nor LOAD_HOSTS is set" >&2
        return 1
    fi

    # Ensure the load session has plenty of fds for large concurrencies.
    ssh_run "${host}" "ulimit -n 1048576 2>/dev/null; ${path} $*"
}

# remote_run_load_shard tool mode "-h host -p port" -c total_concurrency -d duration [extra args...]
# Splits the concurrency across all hosts in LOAD_HOSTS (space-separated) and
# runs them in parallel. Emits aggregated key=value summary to stdout.
#
# Used by scenarios that can benefit from more source IPs (ephemeral port
# ceiling on a single host). For small concurrency, falls back to a single run.
remote_run_load_shard() {
    local tool="$1" mode_or_empty="$2"
    shift 2
    # Parse args to find -c and -d
    local total_c="" duration="" target_args=()
    local a
    while (( $# > 0 )); do
        case "$1" in
            -c) total_c="$2"; shift 2 ;;
            -d) duration="$2"; shift 2 ;;
            *)  target_args+=("$1"); shift ;;
        esac
    done
    if [[ -z "${total_c}" || -z "${duration}" ]]; then
        echo "[harness] remote_run_load_shard missing -c or -d" >&2
        return 1
    fi

    local path
    case "${tool}" in
        tcpbench)  path="${LOAD_REMOTE_DIR}/benchmarks/tcpbench" ;;
        httpbench) path="${LOAD_REMOTE_DIR}/benchmarks/httpbench/httpbench" ;;
        *) echo "[harness] unknown load tool: ${tool}" >&2; return 1 ;;
    esac

    # Resolve hosts list (LOAD_HOSTS preferred; else LOAD_HOST alone).
    local hosts=()
    if [[ -n "${LOAD_HOSTS:-}" ]]; then
        # shellcheck disable=SC2206
        hosts=(${LOAD_HOSTS//,/ })
    else
        hosts=("${LOAD_HOST}")
    fi
    local n=${#hosts[@]}
    if (( n == 0 )); then echo "[harness] no load hosts configured" >&2; return 1; fi

    local per=$(( total_c / n ))
    local rem=$(( total_c - per * n ))

    local tmpdir
    tmpdir="$(mktemp -d)"
    local i=0
    for h in "${hosts[@]}"; do
        local c=${per}
        if (( i < rem )); then c=$(( c + 1 )); fi
        (
            # shellcheck disable=SC2086
            ssh ${SSH_OPTS} "${h}" \
                "ulimit -n 1048576 2>/dev/null; ${path} ${mode_or_empty} ${target_args[*]} -c ${c} -d ${duration}" \
                > "${tmpdir}/out.${i}" 2>&1
        ) &
        i=$(( i + 1 ))
    done
    wait

    # Aggregate key=value outputs across all shards.
    python3 - "${tmpdir}" <<'PY'
import sys, os, re, glob
d = sys.argv[1]
agg = {"total_ops": 0, "bytes": 0, "errors": 0, "total_conns": 0, "held_seconds": 0.0, "avg_latency_us": 0.0, "ops_per_sec": 0.0, "throughput_gbps": 0.0}
counts = {"avg_latency_us": 0, "held_seconds": 0}
raw = {}
for fp in sorted(glob.glob(os.path.join(d, "out.*"))):
    for line in open(fp):
        line = line.strip()
        if "=" not in line: continue
        k, _, v = line.partition("=")
        raw.setdefault(k, []).append(v)
def as_num(x):
    x = x.rstrip("s")
    try: return float(x)
    except: return 0.0
ops = sum(int(as_num(v)) for v in raw.get("total_ops", []))
conns = sum(int(as_num(v)) for v in raw.get("total_conns", []))
errs = sum(int(as_num(v)) for v in raw.get("errors", []))
bts  = sum(int(as_num(v)) for v in raw.get("bytes", []))
durs = [as_num(v) for v in raw.get("held_seconds", [])]
dur  = max(durs) if durs else 0.0
lat_vals = [as_num(v) for v in raw.get("avg_latency_us", [])]
avg_lat = sum(lat_vals)/len(lat_vals) if lat_vals else 0.0
ops_per_sec_vals = [as_num(v) for v in raw.get("ops_per_sec", [])]
ops_per_sec = sum(ops_per_sec_vals) if ops_per_sec_vals else (ops / dur if dur else 0.0)
gbps_vals = [as_num(v) for v in raw.get("throughput_gbps", [])]
gbps = sum(gbps_vals) if gbps_vals else 0.0
print(f"total_ops={ops}")
print(f"total_conns={conns}")
print(f"errors={errs}")
print(f"bytes={bts}")
print(f"held_seconds={dur}")
print(f"avg_latency_us={avg_lat}")
print(f"ops_per_sec={ops_per_sec}")
print(f"throughput_gbps={gbps}")
PY
    rm -rf "${tmpdir}"
}

remote_check_tools() {
    local errors=0
    if [[ -n "${PROXY_HOST:-}" ]]; then
        if ! ssh_run "${PROXY_HOST}" "test -d ${PROXY_REMOTE_DIR}" 2>/dev/null; then
            echo "[harness] ${PROXY_HOST}: ${PROXY_REMOTE_DIR} missing -- run deploy.sh" >&2
            errors=$((errors + 1))
        fi
    fi
    local hosts=()
    if [[ -n "${LOAD_HOSTS:-}" ]]; then
        # shellcheck disable=SC2206
        hosts=(${LOAD_HOSTS//,/ })
    elif [[ -n "${LOAD_HOST:-}" ]]; then
        hosts=("${LOAD_HOST}")
    fi
    for h in "${hosts[@]}"; do
        if ! ssh_run "${h}" "test -x ${LOAD_REMOTE_DIR}/benchmarks/tcpbench && test -x ${LOAD_REMOTE_DIR}/benchmarks/httpbench/httpbench" 2>/dev/null; then
            echo "[harness] ${h}: load generators missing -- run deploy.sh or distribute manually" >&2
            errors=$((errors + 1))
        fi
    done
    return "${errors}"
}

HARNESS_REMOTE_PIDS=()

remote_cleanup() {
    local entry host pid pidfile
    for entry in "${HARNESS_REMOTE_PIDS[@]:-}"; do
        [[ -z "${entry}" ]] && continue
        IFS=':' read -r host pid pidfile <<<"${entry}"
        ssh_run "${host}" "kill -KILL ${pid} 2>/dev/null || true; rm -f ${pidfile} || true" 2>/dev/null || true
    done
    HARNESS_REMOTE_PIDS=()
}

proxy_connect_host() {
    # Prefer the explicit PROXY_CONNECT_HOST (e.g. private VPC IP) so load
    # generators take the short path between droplets.
    if [[ -n "${PROXY_CONNECT_HOST:-}" ]]; then
        echo "${PROXY_CONNECT_HOST}"
    elif [[ -n "${PROXY_HOST:-}" ]]; then
        echo "${PROXY_HOST#*@}"
    else
        echo "127.0.0.1"
    fi
}
