#!/usr/bin/env python3
"""Generate a markdown report from benchmark CSVs.

Reads every CSV in the results directory, groups rows by (scenario, impl),
and emits a human-readable table per scenario with a Delta (Rust vs PHP) column.

Columns expected in every CSV (header row):
  timestamp,impl,scenario,concurrency,duration,ops_per_sec,conns_per_sec,
  avg_latency_us,rss_peak_kb,rss_per_conn_kb
"""

from __future__ import annotations

import argparse
import csv
import statistics
from pathlib import Path


SCENARIOS = {
    'ram': {
        'title': 'Connections per MB of RAM',
        'primary_col': 'rss_per_conn_kb',
        'summary': 'max_conns_per_mb',
    },
    'http_rps': {
        'title': 'HTTP sustained RPS',
        'primary_col': 'ops_per_sec',
        'summary': 'max_ops_per_sec',
    },
    'tcp_conn_rate': {
        'title': 'TCP accept-path throughput',
        'primary_col': 'conns_per_sec',
        'summary': 'max_conns_per_sec',
    },
}


def load_rows(results_dir: Path) -> list[dict[str, str]]:
    rows: list[dict[str, str]] = []
    for csv_path in sorted(results_dir.glob('*.csv')):
        with csv_path.open(newline='') as fh:
            reader = csv.DictReader(fh)
            for row in reader:
                rows.append(row)
    return rows


def to_float(value: str) -> float:
    if value is None or value == '':
        return 0.0
    try:
        return float(value)
    except ValueError:
        return 0.0


def delta_pct(rust: float, php: float) -> str:
    if php == 0:
        return 'n/a'
    pct = (rust - php) / php * 100.0
    sign = '+' if pct >= 0 else ''
    return f'{sign}{pct:.1f}%'


def group_rows(rows: list[dict[str, str]]) -> dict[str, dict[str, list[dict[str, str]]]]:
    grouped: dict[str, dict[str, list[dict[str, str]]]] = {}
    for row in rows:
        scenario = row.get('scenario', '')
        impl = row.get('impl', '')
        grouped.setdefault(scenario, {}).setdefault(impl, []).append(row)
    return grouped


def is_valid_ram_row(row: dict[str, str]) -> bool:
    """Filter out crashed / OOM'd scenarios that produced junk math.

    A legitimate RAM sample has:
      - rss_peak_kb >= the proxy's idle footprint (rough floor: 2 MB)
      - rss_per_conn_kb reasonable (< 10 MB/conn — well above observed ceiling)
    Rows outside these bounds come from the proxy dying mid-batch; drop them.
    """
    rss = to_float(row.get('rss_peak_kb', '0'))
    per_conn = to_float(row.get('rss_per_conn_kb', '0'))
    conns_per_mb = to_float(row.get('conns_per_sec', '0'))  # stored under conns_per_sec column
    if rss < 2048:
        return False
    if per_conn <= 0 or per_conn > 10240:
        return False
    # Rows where the load gen tipped over (total_conns << batch) silently inflate
    # conns_per_mb because the harness divides by batch. ~300 is a generous
    # ceiling for commodity hardware with default socket buffers.
    if conns_per_mb > 300:
        return False
    return True


def render_ram(groups: dict[str, list[dict[str, str]]]) -> str:
    lines: list[str] = []
    lines.append('| concurrency | impl | rss_peak_kb | rss_per_conn_kb | conns_per_mb |')
    lines.append('|---|---|---|---|---|')

    # Keep only the latest valid row per (concurrency, impl). `rows` is already
    # in chronological order because load_rows iterates sorted CSV files.
    concurrency_set: set[int] = set()
    by_key: dict[tuple[int, str], dict[str, str]] = {}
    for impl, items in groups.items():
        for row in items:
            if not is_valid_ram_row(row):
                continue
            c = int(to_float(row.get('concurrency', '0')))
            concurrency_set.add(c)
            by_key[(c, impl)] = row

    for c in sorted(concurrency_set):
        for impl in ('php', 'rust'):
            row = by_key.get((c, impl))
            if row is None:
                continue
            lines.append(
                f'| {c} | {impl} | {row.get("rss_peak_kb", "0")} '
                f'| {row.get("rss_per_conn_kb", "0")} '
                f'| {row.get("conns_per_sec", "0")} |'
            )

    # Summary: best (max) conns_per_mb per impl using only valid rows.
    lines.append('')
    lines.append('**Summary** (higher is better):')
    lines.append('')
    lines.append('| impl | max conns/MB | max concurrency reached |')
    lines.append('|---|---|---|')
    summary: dict[str, tuple[float, int]] = {}
    for impl, items in groups.items():
        valid = [r for r in items if is_valid_ram_row(r)]
        if not valid:
            continue
        max_conns_per_mb = max((to_float(r.get('conns_per_sec', '0')) for r in valid), default=0.0)
        max_concurrency = max((int(to_float(r.get('concurrency', '0'))) for r in valid), default=0)
        summary[impl] = (max_conns_per_mb, max_concurrency)
    for impl in ('php', 'rust'):
        if impl in summary:
            v, c = summary[impl]
            lines.append(f'| {impl} | {v:.2f} | {c} |')
    php_val = summary.get('php', (0.0, 0))[0]
    rust_val = summary.get('rust', (0.0, 0))[0]
    if php_val > 0 and rust_val > 0:
        lines.append('')
        lines.append(f'**Delta (rust vs php):** {delta_pct(rust_val, php_val)} conns/MB')
    return '\n'.join(lines)


def render_throughput(groups: dict[str, list[dict[str, str]]], value_col: str, label: str) -> str:
    lines: list[str] = []
    lines.append(f'| concurrency | impl | {label} | avg_latency_us | rss_peak_kb |')
    lines.append('|---|---|---|---|---|')

    # Keep the most recent row with a non-zero value per (concurrency, impl).
    # Zero-valued rows are usually stale failures (backend bind collision etc.)
    # that were corrected on subsequent runs.
    concurrency_set: set[int] = set()
    by_key: dict[tuple[int, str], dict[str, str]] = {}
    for impl, items in groups.items():
        for row in items:
            v = to_float(row.get(value_col, '0'))
            if v <= 0:
                continue
            c = int(to_float(row.get('concurrency', '0')))
            concurrency_set.add(c)
            existing = by_key.get((c, impl))
            if existing is None or to_float(existing.get('timestamp', '0')) < to_float(row.get('timestamp', '0')):
                by_key[(c, impl)] = row

    for c in sorted(concurrency_set):
        php_row = by_key.get((c, 'php'))
        rust_row = by_key.get((c, 'rust'))
        for impl, row in (('php', php_row), ('rust', rust_row)):
            if row is None:
                continue
            lines.append(
                f'| {c} | {impl} | {row.get(value_col, "0")} '
                f'| {row.get("avg_latency_us", "0")} '
                f'| {row.get("rss_peak_kb", "0")} |'
            )
        if php_row is not None and rust_row is not None:
            php_v = to_float(php_row.get(value_col, '0'))
            rust_v = to_float(rust_row.get(value_col, '0'))
            lines.append(f'| {c} | **delta** | {delta_pct(rust_v, php_v)} | | |')

    lines.append('')
    lines.append('**Summary** (higher is better):')
    lines.append('')
    lines.append(f'| impl | max {label} |')
    lines.append('|---|---|')
    summary: dict[str, float] = {}
    for impl, items in groups.items():
        valid = [to_float(r.get(value_col, '0')) for r in items if to_float(r.get(value_col, '0')) > 0]
        summary[impl] = max(valid, default=0.0)
    for impl in ('php', 'rust'):
        if impl in summary:
            lines.append(f'| {impl} | {summary[impl]:.0f} |')
    php_val = summary.get('php', 0.0)
    rust_val = summary.get('rust', 0.0)
    if php_val > 0 and rust_val > 0:
        lines.append('')
        lines.append(f'**Delta (rust vs php):** {delta_pct(rust_val, php_val)}')
    return '\n'.join(lines)


def scan_idle_rss(logs_dir: Path | None) -> dict[str, int]:
    """Parse `[ram] idle RSS: NNN KB` lines from bench runs in /tmp and the
    harness logs directory. Returns the LAST value seen per impl — i.e. the
    most recent run's measurement.

    The idle RSS is measured right after the proxy starts accepting, before
    any load, so it doesn't live in the per-scenario CSV.
    """
    import re
    latest: dict[str, tuple[float, int]] = {}
    candidates: list[Path] = []
    if logs_dir and logs_dir.exists():
        candidates.extend(sorted(logs_dir.glob('*.log')))
    # Also scan bench runs in /tmp so we don't lose values from the
    # orchestrator's top-level log which isn't under results/.
    tmp = Path('/tmp')
    if tmp.exists():
        candidates.extend(sorted(tmp.glob('bench*.log')))

    for log in candidates:
        try:
            text = log.read_text(errors='ignore')
        except OSError:
            continue
        mtime = log.stat().st_mtime
        # Find the impl being benchmarked then the idle line; associate the
        # nearest preceding `=== <impl> / ram ===` header with the value.
        impl = None
        for line in text.splitlines():
            m = re.match(r'===\s*(\w+)\s*/\s*(\w+)\s*===', line)
            if m and m.group(2) == 'ram':
                impl = m.group(1)
                continue
            m = re.search(r'\[ram\]\s*idle RSS:\s*(\d+)\s*KB', line)
            if m and impl:
                kb = int(m.group(1))
                prev = latest.get(impl)
                if prev is None or prev[0] < mtime:
                    latest[impl] = (mtime, kb)
    return {impl: kb for impl, (_, kb) in latest.items()}


def render_memory_summary(rows: list[dict[str, str]], logs_dir: Path) -> str:
    """Per-scenario idle + peak RSS for each impl, with % decrease vs PHP."""
    lines: list[str] = []
    idle = scan_idle_rss(logs_dir)

    scenarios = {
        'ram': 'TCP idle connections',
        'http_rps': 'HTTP request/response',
        'tcp_conn_rate': 'TCP accept-path',
    }

    lines.append('| scenario | php idle | rust idle | Δ idle | php peak | rust peak | Δ peak |')
    lines.append('|---|---|---|---|---|---|---|')

    def _fmt_mb(kb: float) -> str:
        return f'{kb / 1024:.1f} MB'

    def _fmt_delta(rust_kb: float, php_kb: float) -> str:
        if php_kb <= 0:
            return '—'
        pct = (rust_kb - php_kb) / php_kb * 100.0
        return f'{pct:+.1f}%'

    for scenario_key, scenario_label in scenarios.items():
        peaks: dict[str, float] = {}
        for row in rows:
            if row.get('scenario') != scenario_key:
                continue
            rss = to_float(row.get('rss_peak_kb', '0'))
            if rss <= 0:
                continue
            if scenario_key == 'ram' and not is_valid_ram_row(row):
                continue
            impl = row.get('impl', '')
            if rss > peaks.get(impl, 0.0):
                peaks[impl] = rss

        php_idle = float(idle.get('php', 0))
        rust_idle = float(idle.get('rust', 0))
        php_peak = peaks.get('php', 0.0)
        rust_peak = peaks.get('rust', 0.0)

        lines.append(
            f'| {scenario_label} | {_fmt_mb(php_idle)} | {_fmt_mb(rust_idle)} | {_fmt_delta(rust_idle, php_idle)} '
            f'| {_fmt_mb(php_peak)} | {_fmt_mb(rust_peak)} | {_fmt_delta(rust_peak, php_peak)} |'
        )

    return '\n'.join(lines)


def build_report(results_dir: Path) -> str:
    rows = load_rows(results_dir)
    grouped = group_rows(rows)
    logs_dir = results_dir / 'logs'

    parts: list[str] = []
    parts.append('# Proxy benchmark report')
    parts.append('')
    if not rows:
        parts.append('_No results found. Run `benchmarks/harness/run.sh` first._')
        return '\n'.join(parts)

    parts.append(f'Scenarios recorded: {sorted(grouped.keys())}')
    parts.append('')

    # Memory summary goes up top — it's the headline comparison.
    parts.append('## Memory footprint (idle vs peak)')
    parts.append('')
    parts.append('Negative `vs php` = Rust uses *less* memory; positive = more.')
    parts.append('')
    parts.append(render_memory_summary(rows, logs_dir))
    parts.append('')

    for scenario_key, meta in SCENARIOS.items():
        if scenario_key not in grouped:
            continue
        parts.append(f'## {meta["title"]} (`{scenario_key}`)')
        parts.append('')
        if scenario_key == 'ram':
            parts.append(render_ram(grouped[scenario_key]))
        elif scenario_key == 'http_rps':
            parts.append(render_throughput(grouped[scenario_key], 'ops_per_sec', 'ops/sec'))
        elif scenario_key == 'tcp_conn_rate':
            parts.append(render_throughput(grouped[scenario_key], 'conns_per_sec', 'conns/sec'))
        parts.append('')

    parts.append('---')
    parts.append('')
    parts.append(f'Total rows: {len(rows)}')
    if rows:
        timestamps = sorted({r.get('timestamp', '') for r in rows if r.get('timestamp')})
        if timestamps:
            parts.append(f'First recorded: {timestamps[0]}')
            parts.append(f'Last recorded:  {timestamps[-1]}')
    return '\n'.join(parts)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--results-dir', type=Path, default=Path(__file__).parent / 'results')
    parser.add_argument('--output', type=Path, default=None,
                        help='output file (default: <results-dir>/report.md)')
    args = parser.parse_args()

    results_dir: Path = args.results_dir
    output: Path = args.output if args.output is not None else results_dir / 'report.md'
    results_dir.mkdir(parents=True, exist_ok=True)

    report = build_report(results_dir)
    output.write_text(report, encoding='utf-8')
    # Unused import silencer -- statistics kept for future percentile support.
    _ = statistics.mean
    print(str(output))


if __name__ == '__main__':
    main()
