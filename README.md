# Utopia Proxy

High-performance, protocol-agnostic proxy built on Swoole for HTTP, TCP, and SMTP.

## Performance

- **670k+ concurrent connections** per server (8-core/32GB)
- **~33KB per connection** memory footprint
- **18k+ connections/sec** sustained connection rate
- **60k+ ops/sec** TCP request/response throughput (1KB payloads)
- **BPF sockmap** optional kernel zero-copy relay for CPU-bound workloads

| Metric | Result |
|--------|--------|
| Peak concurrent connections | 672,348 |
| Memory per connection | ~33 KB |
| Connection rate (sustained) | 18,067/sec |
| CPU at peak | ~60% |

## Benchmarks

The `benchmarks/harness/` directory contains a language-agnostic benchmark harness
that compares the PHP/Swoole implementation against the Rust port on three axes
-- connections per MB of RAM, HTTP RPS, and TCP connections per second. Results
land in a single CSV plus a markdown report with Rust-vs-PHP deltas. See
[`benchmarks/harness/README.md`](benchmarks/harness/README.md) for usage and the
C load generators at `benchmarks/tcpbench.c` and `benchmarks/httpbench/`.

## Requirements

- PHP >= 8.4
- ext-swoole >= 6.0
- ext-redis

## Installation

```bash
composer require utopia-php/proxy
```

## Quick Start

The proxy resolves routing input to backend endpoints via the `Resolver` interface — one method, one job:

```php
interface Resolver
{
    public function resolve(string $data): Result;
}
```

`$data` is protocol-specific: raw TCP packet bytes, HTTP hostname, SMTP domain. The proxy doesn't parse it — your resolver does.

### HTTP Proxy

```php
use Utopia\Proxy\Resolver;
use Utopia\Proxy\Resolver\Result;
use Utopia\Proxy\Server\HTTP\Config;
use Utopia\Proxy\Server\HTTP\Swoole as HTTPServer;

$resolver = new class implements Resolver {
    public function resolve(string $data): Result
    {
        return match ($data) {
            'api.example.com' => new Result(endpoint: 'localhost:3000'),
            'app.example.com' => new Result(endpoint: 'localhost:3001'),
            default => throw new \Utopia\Proxy\Resolver\Exception("Unknown: {$data}", 404),
        };
    }
};

$config = new Config(port: 8080, workers: swoole_cpu_num() * 2);
(new HTTPServer($resolver, $config))->start();
```

### TCP Proxy

```php
use Utopia\Proxy\Resolver\Fixed;
use Utopia\Proxy\Server\TCP\Config;
use Utopia\Proxy\Server\TCP\Swoole as TCPServer;

$resolver = new Fixed('10.0.0.2:5432');

$config = new Config(
    ports: [5432, 3306],
    workers: swoole_cpu_num(),
);

(new TCPServer($resolver, $config))->start();
```

For custom routing, parse the raw packet data in your resolver:

```php
$adapter->onResolve(function (string $data) use ($resolver): string {
    $databaseId = MyPacketParser::extractDatabase($data);
    return $resolver->resolve($databaseId);
});
```

### SMTP Proxy

```php
use Utopia\Proxy\Resolver\Fixed;
use Utopia\Proxy\Server\SMTP\Config;
use Utopia\Proxy\Server\SMTP\Swoole as SMTPServer;

$resolver = new Fixed('smtp-backend:1025');
$config = new Config(port: 25);
(new SMTPServer($resolver, $config))->start();
```

## Docker

The Docker image ships a generic entrypoint (`bin/proxy`) that supports all three protocols. Mount a resolver file or use the built-in `Fixed` resolver with environment variables.

### Fixed backend (no mount needed)

```bash
docker build -t utopia/proxy .

# TCP proxy
docker run --rm --network=host \
  -e TCP_BACKEND_ENDPOINT=10.0.0.2:5432 \
  utopia/proxy

# HTTP proxy
docker run --rm -p 8080:8080 \
  -e HTTP_BACKEND_ENDPOINT=10.0.0.2:5678 \
  utopia/proxy http

# SMTP proxy
docker run --rm -p 25:25 \
  -e SMTP_BACKEND_ENDPOINT=10.0.0.2:1025 \
  utopia/proxy smtp
```

### Custom resolver (mount pattern)

Mount a PHP file that returns a `Resolver` instance, like mounting `haproxy.cfg` for HAProxy:

```bash
docker run --rm --network=host \
  -v ./resolver.php:/etc/utopia-proxy/resolver.php:ro \
  utopia/proxy tcp
```

Where `resolver.php` is:

```php
<?php
return new class implements Utopia\Proxy\Resolver {
    public function resolve(string $data): Utopia\Proxy\Resolver\Result
    {
        $databaseId = parseStartupMessage($data);
        $endpoint = lookupFromRedis($databaseId);
        return new Utopia\Proxy\Resolver\Result(endpoint: $endpoint);
    }
};
```

If your resolver needs Composer dependencies, mount a project directory:

```bash
docker run --rm --network=host \
  -v ./my-proxy:/app:ro \
  -e PROXY_RESOLVER=/app/resolver.php \
  utopia/proxy tcp
```

The entrypoint loads `/app/vendor/autoload.php` if present.

### Docker Compose

```bash
docker compose up --build              # HTTP proxy on :8080
docker compose up --build tcp-proxy    # TCP proxy on :5432/:3306
docker compose up --build smtp-proxy   # SMTP proxy on :25
```

### Sockmap (kernel zero-copy)

For TCP workloads where proxy CPU is the bottleneck, enable BPF sockmap to bypass userspace:

```bash
docker run --rm --network=host --user root \
  --cap-add=BPF --cap-add=NET_ADMIN --cap-add=SYS_RESOURCE \
  -e TCP_SOCKMAP_ENABLED=1 \
  -e TCP_BACKEND_ENDPOINT=10.0.0.2:5432 \
  utopia/proxy
```

Requires Linux 4.17+, `ext-ffi`, and `libbpf`. See [benchmarks/README.md](benchmarks/README.md) for details.

## TLS Termination

```php
use Utopia\Proxy\Server\TCP\Config;
use Utopia\Proxy\Server\TCP\TLS;
use Utopia\Proxy\Server\TCP\Swoole as TCPServer;

$tls = new TLS(
    certificate: '/certs/server.crt',
    key: '/certs/server.key',
    ca: '/certs/ca.crt',
    requireClientCert: true,
);

$config = new Config(ports: [5432, 3306], tls: $tls);
(new TCPServer($resolver, $config))->start();
```

Supported: PostgreSQL STARTTLS, MySQL SSL handshake. Environment variables: `PROXY_TLS_ENABLED`, `PROXY_TLS_CERT`, `PROXY_TLS_KEY`, `PROXY_TLS_CA`, `PROXY_TLS_REQUIRE_CLIENT_CERT`.

## Environment Variables

**TCP:**

| Variable | Default | Description |
|----------|---------|-------------|
| `TCP_BACKEND_ENDPOINT` | `127.0.0.1:5432` | Fixed backend endpoint |
| `TCP_POSTGRES_PORT` | `5432` | PostgreSQL listen port |
| `TCP_MYSQL_PORT` | `3306` | MySQL listen port |
| `TCP_WORKERS` | `cpu_num` | Worker processes |
| `TCP_SKIP_VALIDATION` | `false` | Disable SSRF protection |
| `TCP_SOCKMAP_ENABLED` | `false` | Enable BPF sockmap relay |
| `TCP_SOCKMAP_BPF_OBJECT` | | Path to compiled relay.bpf.o |

**HTTP:**

| Variable | Default | Description |
|----------|---------|-------------|
| `HTTP_BACKEND_ENDPOINT` | `127.0.0.1:5678` | Fixed backend endpoint |
| `HTTP_PORT` | `8080` | Listen port |
| `HTTP_WORKERS` | `cpu_num * 2` | Worker processes |
| `HTTP_BACKEND_POOL_SIZE` | `2048` | Connection pool size |
| `HTTP_SKIP_VALIDATION` | `false` | Disable SSRF protection |

**SMTP:**

| Variable | Default | Description |
|----------|---------|-------------|
| `SMTP_BACKEND_ENDPOINT` | `127.0.0.1:1025` | Fixed backend endpoint |
| `SMTP_PORT` | `25` | Listen port |
| `SMTP_SKIP_VALIDATION` | `false` | Disable SSRF protection |

## Testing

```bash
composer test              # Unit tests
composer test:integration  # Integration tests (Docker)
composer test:all          # All tests
composer check             # PHPStan (max level)
composer lint              # Pint (PSR-12)
```

## Benchmarks

```bash
composer bench:build       # Compile C load generator
composer bench:bpf         # Compile BPF sockmap object

# TCP throughput
./benchmarks/tcpbench rr -h 127.0.0.1 -p 5432 -c 200 -d 10 -s 1024

# Kernel tuning
sudo ./benchmarks/setup.sh              # Benchmark mode
sudo ./benchmarks/setup.sh --production # Production mode
```

See [benchmarks/README.md](benchmarks/README.md) for detailed results.

## License

BSD-3-Clause
