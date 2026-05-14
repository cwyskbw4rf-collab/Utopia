# utopia-proxy (Rust)

A 1:1 feature-parity Rust port of the PHP/Swoole proxy library at the repo root.
Built on Tokio. Reuses the same BPF sockmap program (`src/Sockmap/relay.bpf.c`).

## Status

Phase 1 (foundation) — core library modules. Servers (TCP, HTTP, SMTP) land in Phase 2.

## Feature matrix

| Feature | PHP / Swoole | Rust |
|---|---|---|
| TCP proxy | Yes | Phase 2 |
| HTTP proxy | Yes | Phase 2 |
| SMTP proxy | Yes | Phase 2 |
| TLS termination | Yes (OpenSSL) | Yes (rustls) |
| BPF sockmap | Linux only | Linux only (libbpf-rs) |
| Protocol detection | 28 protocols | 28 protocols |
| SSRF protection | Yes | Yes |
| DNS cache | Yes | Yes (hickory) |

## Build

```bash
cd rust
cargo build --workspace --release
```

## Run

```bash
./target/release/proxy tcp     # TCP proxy (Phase 2)
./target/release/proxy http    # HTTP proxy (Phase 2)
./target/release/proxy smtp    # SMTP proxy (Phase 2)
```

## Test

```bash
cargo test --workspace
```

## Lint

```bash
cargo fmt --check
cargo clippy --all-targets -- -D warnings
```

## Features

- `sockmap` (Linux only) — BPF sockmap zero-copy relay
- `jemalloc` (default off) — swap system allocator for jemalloc

## Parity notes

- Protocol enum, port→protocol mapping, resolver contract, SSRF rules, DNS cache TTL,
  TCP adapter builder methods, and sockmap 4-tuple key encoding all match the PHP
  implementation exactly.
- Tests under `crates/utopia-proxy/tests/` mirror PHP test files 1:1.

## Pointers

- See `../src/` for the PHP reference implementation.
- See `../AGENTS.md` for project-wide conventions.
