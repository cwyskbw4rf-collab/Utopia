# Proxy benchmark harness

Language-agnostic benchmark harness that compares the PHP/Swoole and Rust/Tokio
proxy implementations across three scenarios:

1. **Connections per MB of RAM** -- how many concurrent TCP connections each
   proxy holds per MB of resident memory (idle-ish connections)
2. **HTTP RPS** -- sustained HTTP/1.1 requests per second
3. **TCP connections per second** -- accept-path throughput

One shared load generator, one CSV, one markdown report.

## Layout

```
benchmarks/
├── httpbench/          # minimal pthreads HTTP/1.1 load generator (C)
├── tcpbench.c          # existing TCP load generator (rr, rate, hold modes)
└── harness/
    ├── run.sh          # orchestrator
    ├── lib.sh          # shared bash helpers
    ├── scenarios/
    │   ├── ram_per_connection.sh
    │   ├── http_rps.sh
    │   └── tcp_conn_rate.sh
    ├── targets/
    │   ├── php_tcp.env php_http.env
    │   └── rust_tcp.env rust_http.env
    ├── report.py       # CSV -> markdown
    └── results/        # CSV + report + per-run logs (gitignored)
```

## Prerequisites

### Kernel tuning (Linux)

Run once before benchmarking:

```bash
sudo benchmarks/setup.sh           # aggressive (benchmark)
# or
sudo benchmarks/setup.sh --production  # conservative
```

`lib.sh::check_kernel` warns about `net.core.somaxconn < 32768` and `ulimit -n <
1000000`.

### Load generators

Both generators are plain C + pthreads:

```bash
# existing
( cd benchmarks && cc -O2 -Wall -Wextra -pthread -o tcpbench tcpbench.c )

# new
( cd benchmarks/httpbench && make )
```

### PHP proxy

Install deps in the repo root:

```bash
composer install
```

Requires PHP 8.4+ and ext-swoole 6.0+.

### Rust proxy

Release binary is expected at `rust/target/release/proxy`:

```bash
( cd rust && cargo build --release )
```

If the Rust crate is not yet present, pass `--impl php` to `run.sh` to benchmark
only the PHP side.

## Usage

```bash
# Run everything (both impls, all scenarios, 30s duration).
bash benchmarks/harness/run.sh

# PHP only, HTTP RPS only, 60s.
bash benchmarks/harness/run.sh --impl php --scenario rps --duration 60

# Rust only, RAM scenario only.
bash benchmarks/harness/run.sh --impl rust --scenario ram

# Custom output directory.
bash benchmarks/harness/run.sh --output-dir /tmp/proxy-bench
```

Flags:

- `--impl {php|rust|both}` -- default `both`
- `--scenario {ram|rps|conn-rate|all}` -- default `all`
- `--duration SEC` -- default `30`
- `--output-dir DIR` -- default `benchmarks/harness/results`

Implementations are run strictly sequentially (PHP then Rust), so their listen
ports intentionally collide -- only one proxy is alive at a time.

## Remote / split-host mode

For realistic numbers the load generator and proxy should run on **separate
machines** so neither starves the other for CPU, and every request crosses a
real network. The harness can orchestrate this over SSH.

Required: passwordless key-based SSH to both hosts (`root@...` or a sudo-able
user), rsync on the controller, and `bash` + `rsync` on the targets.

```bash
# One-time deploy: rsync project, install deps, build binaries on each host.
PROXY_HOST=root@1.2.3.4 LOAD_HOST=root@5.6.7.8 \
    bash benchmarks/harness/deploy.sh --install-deps

# Subsequent runs (after a code change, re-deploy without re-installing deps):
PROXY_HOST=root@1.2.3.4 LOAD_HOST=root@5.6.7.8 \
    bash benchmarks/harness/deploy.sh

# Run the harness against the remote hosts:
bash benchmarks/harness/run.sh \
    --proxy-host root@1.2.3.4 --load-host root@5.6.7.8
```

Equivalent env form:

```bash
PROXY_HOST=root@1.2.3.4 LOAD_HOST=root@5.6.7.8 \
    bash benchmarks/harness/run.sh
```

When `PROXY_HOST` is set the harness starts the proxy (+ echo backend) on that
host, samples RSS over SSH, and tears down via SSH. When `LOAD_HOST` is set the
load generator (`tcpbench` / `httpbench`) runs there, pointed at
`$PROXY_HOST:$PORT`. Result CSVs and logs are written on the controller as in
localhost mode.

Paths on targets default to `/opt/utopia-proxy`; override via
`PROXY_REMOTE_DIR` / `LOAD_REMOTE_DIR`. Extra SSH options go into `SSH_OPTS`.

## Output

- `results/<unix-ts>.csv` -- one row per (impl, scenario, concurrency) tuple
  with columns
  `timestamp,impl,scenario,concurrency,duration,ops_per_sec,conns_per_sec,avg_latency_us,rss_peak_kb,rss_per_conn_kb`
- `results/report.md` -- markdown tables with a Delta (Rust vs PHP %) column
- `results/logs/*.log` -- per-process stdout/stderr
- `results/logs/*.out` -- raw key=value output from the load generators

Re-run the harness multiple times; `report.py` aggregates every CSV in the
results directory.

## Interpretation guide

### RAM scenario

- `rss_per_conn_kb` = `(proxy_rss_kb - idle_rss_kb) / concurrent_conns`. Lower
  is better.
- `conns_per_mb` = inverse of the above, scaled to MB. The summary table picks
  the best ratio achieved at any batch size.
- The ramp stops when the tcpbench hold worker reports > 5% errors (usually fd
  exhaustion or accept queue overflow). If you want to push further, bump
  `ulimit -n` and rerun.

### HTTP RPS scenario

- httpbench uses HTTP/1.1 keep-alive (`-k`) so each worker thread issues a
  tight pipeline of GETs over one socket.
- `ops_per_sec` peaks when concurrency * latency roughly matches the backend
  and proxy service times. A higher ceiling at comparable latency = a faster
  proxy.

### TCP conn-rate scenario

- Each worker does `connect -> send pg startup -> recv first response ->
  close`, looped. It measures the accept + first-response path, not bulk
  forwarding.

## Extending

Add a new scenario:

1. Drop a script in `scenarios/` that accepts `<impl> <csv_path>` and appends
   rows via `record_csv`.
2. Wire a case into `run.sh::run_scenario` and the `SCENARIO` parser.
3. Add a `SCENARIOS` entry in `report.py` with a renderer.

Add a new target:

1. Drop an env file in `targets/<impl>_<protocol>.env` exporting the required
   env vars and setting `BIN` to the launch command.
2. Use the new `<impl>` value with `--impl`.
