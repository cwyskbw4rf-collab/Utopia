<?php

namespace Utopia\Proxy\Server\TCP;

use Swoole\Constant;
use Swoole\Coroutine;
use Swoole\Coroutine\Client;
use Swoole\Coroutine\Socket;
use Swoole\Server;
use Swoole\Server\Port;
use Swoole\Timer;
use Utopia\Console;
use Utopia\Proxy\Adapter\TCP as TCPAdapter;
use Utopia\Proxy\Dns;
use Utopia\Proxy\Resolver;
use Utopia\Proxy\Sockmap\Loader as Sockmap;

/**
 * High-performance TCP proxy server (Swoole Implementation)
 *
 * Defaults to SWOOLE_BASE mode: each worker owns its own reactor and listen
 * socket (via SO_REUSEPORT), removing the reactor → worker IPC pipe used in
 * PROCESS mode. This matches HAProxy's nbthread-per-core model and roughly
 * doubles small-request throughput on CPU-bound forwarding workloads.
 *
 * Supports optional TLS termination:
 * - PostgreSQL: STARTTLS via SSLRequest/SSLResponse handshake
 * - MySQL: SSL capability flag in server greeting
 *
 * When TLS is enabled, the server uses SWOOLE_SOCK_TCP | SWOOLE_SSL socket type
 * and Swoole handles the TLS handshake natively. For PostgreSQL STARTTLS, the
 * proxy intercepts the SSLRequest message, responds with 'S', and Swoole
 * upgrades the connection to TLS before forwarding the subsequent startup message.
 *
 * Example:
 * ```php
 * $tls = new TLS(certificate: '/certs/server.crt', key: '/certs/server.key');
 * $config = new Config(ports: [5432, 3306], tls: $tls);
 * $server = new Swoole($config, $resolver);
 * $server->start();
 * ```
 */
class Swoole
{
    public protected(set) Server $server;

    /** @var array<int, TCPAdapter> */
    protected array $adapters = [];

    protected Config $config;

    protected ?Sockmap $sockmap = null;

    protected ?TLSContext $tlsContext = null;

    /**
     * Per-fd connection state, keyed by file descriptor.
     *
     * @var array<int, Connection>
     */
    protected array $connections = [];

    protected ?Resolver $resolver;

    public function __construct(
        ?Resolver $resolver = null,
        ?Config $config = null,
    ) {
        $this->resolver = $resolver;
        $this->config = $config ?? new Config(ports: [5432]);

        if ($this->config->isTlsEnabled()) {
            /** @var TLS $tls */
            $tls = $this->config->tls;
            $tls->validate();
            $this->tlsContext = $this->config->getTLSContext();
        }

        $socketType = $this->tlsContext !== null
            ? $this->tlsContext->getSocketType()
            : SWOOLE_SOCK_TCP;

        $this->server = new Server(
            $this->config->host,
            $this->config->ports[0],
            $this->config->serverMode,
            $socketType,
        );

        // Additional ports are attached as listeners; each gets its own
        // receive handler so the port is captured in the closure rather than
        // looked up on every packet.
        for ($i = 1; $i < \count($this->config->ports); $i++) {
            $this->server->addlistener(
                $this->config->host,
                $this->config->ports[$i],
                $socketType,
            );
        }

        $this->configure();
    }

    protected function configure(): void
    {
        $settings = [
            'worker_num' => $this->config->workers,
            'reactor_num' => $this->config->reactorNum,
            'max_connection' => $this->config->maxConnections,
            'max_coroutine' => $this->config->maxCoroutine,
            'socket_buffer_size' => $this->config->socketBufferSize,
            'buffer_output_size' => $this->config->bufferOutputSize,
            'enable_coroutine' => $this->config->enableCoroutine,
            'max_wait_time' => $this->config->maxWaitTime,
            'log_level' => $this->config->logLevel,
            'enable_reuse_port' => $this->config->enableReusePort,
            'backlog' => $this->config->backlog,

            // TCP performance tuning
            'open_tcp_nodelay' => true,
            'tcp_fastopen' => true,
            'open_cpu_affinity' => true,
            'tcp_defer_accept' => 5,
            'open_tcp_keepalive' => true,
            'tcp_keepidle' => $this->config->tcpKeepidle,
            'tcp_keepinterval' => $this->config->tcpKeepinterval,
            'tcp_keepcount' => $this->config->tcpKeepcount,

            'open_length_check' => false,
            'package_max_length' => $this->config->packageMaxLength,

            'task_enable_coroutine' => true,
        ];

        if ($this->tlsContext !== null) {
            $settings = \array_merge($settings, $this->tlsContext->toSwooleConfig());
        }

        $this->server->set($settings);

        $this->server->on(Constant::EVENT_START, $this->onStart(...));
        $this->server->on(Constant::EVENT_WORKER_START, $this->onWorkerStart(...));
        $this->server->on(Constant::EVENT_CLOSE, $this->onClose(...));

        // Bind one Receive handler per port with the port captured in-closure.
        // This avoids a getClientInfo() syscall per new connection and keeps
        // the routing dispatch zero-cost once a fd is known.
        $primaryPort = $this->config->ports[0];
        foreach ($this->config->ports as $index => $port) {
            $handler = function (Server $server, int $fd, int $reactorId, string $data) use ($port): void {
                $this->onReceive($server, $fd, $data, $port);
            };

            if ($port === $primaryPort) {
                $this->server->on(Constant::EVENT_RECEIVE, $handler);

                continue;
            }

            $listener = $this->resolveListener($index);
            if ($listener !== null) {
                $listener->on('Receive', $handler);
            }
        }
    }

    private function resolveListener(int $index): ?Port
    {
        /** @var array<int, Port> $ports */
        $ports = $this->server->ports;

        return $ports[$index] ?? null;
    }

    public function onStart(Server $server): void
    {
        Console::success("TCP Proxy Server started at {$this->config->host}");
        Console::log('Ports: '.\implode(', ', $this->config->ports));
        Console::log("Workers: {$this->config->workers}");
        Console::log("Max connections: {$this->config->maxConnections}");
        Console::log('Server mode: '.($this->config->serverMode === SWOOLE_BASE ? 'BASE' : 'PROCESS'));

        $jitStatus = self::detectJitStatus();
        if ($jitStatus !== null) {
            Console::log('JIT: '.$jitStatus);
        }

        if ($this->config->isTlsEnabled()) {
            Console::info('TLS: enabled');
            if ($this->config->tls?->isMutual()) {
                Console::info('mTLS: enabled (client certificates required)');
            }
        }
    }

    /**
     * Report whether the JIT is actually enabled inside this Swoole worker.
     *
     * PHP 8.3+ shipped a fix that lets OPcache's tracing JIT coexist with
     * Swoole's opcode handlers, but it silently stays off on older builds
     * or when opcache.enable_cli is 0. Logging at startup makes JIT
     * misconfiguration visible in ops.
     */
    private static function detectJitStatus(): ?string
    {
        if (!\function_exists('opcache_get_status')) {
            return null;
        }

        /** @var array<string, mixed>|false $status */
        $status = @\opcache_get_status(false);
        if (!\is_array($status)) {
            return 'unavailable (opcache off)';
        }

        /** @var array<string, mixed> $jit */
        $jit = \is_array($status['jit'] ?? null) ? $status['jit'] : [];
        $on = $jit['on'] ?? false;

        return $on ? 'enabled' : 'disabled';
    }

    public function onWorkerStart(Server $server, int $workerId): void
    {
        // Disable GC in long-lived workers and collect cycles on a timer.
        // Stops GC sweeps from stalling the reactor under high allocation churn.
        \gc_disable();
        Timer::tick($this->config->gcIntervalMs, static function (): void {
            \gc_collect_cycles();
        });

        // Drop the coroutine stack from Swoole's 2MB default to 256KB: the
        // forward loop is shallow and doesn't need 2MB of C stack per
        // connection. Going smaller than ~128KB breaks PHP's zend.reserved
        // stack margin, so 256KB is the conservative floor.
        Coroutine::set([
            'stack_size' => $this->config->coroutineStackSize,
            'max_coroutine' => $this->config->maxCoroutine,
        ]);

        Dns::setTtl($this->config->dnsCacheTtl);

        // Load the BPF sockmap once per worker. If the object file is missing,
        // libbpf isn't installed, or the kernel rejects the load (no CAP_BPF,
        // BPF disabled), we continue without zero-copy relay and fall through
        // to the existing userspace forward() path.
        if ($this->config->sockmapEnabled && $this->config->sockmapBpfObject !== '') {
            $loader = new Sockmap($this->config->sockmapBpfObject);
            if ($loader->load()) {
                $this->sockmap = $loader;
                Console::log('Sockmap: enabled (kernel zero-copy relay)');
            } else {
                Console::log('Sockmap: unavailable ('.$loader->lastError().')');
            }
        }

        foreach ($this->config->ports as $port) {
            if ($this->config->adapterFactory !== null) {
                /** @var TCPAdapter $adapter */
                $adapter = ($this->config->adapterFactory)($port);
            } else {
                $adapter = new TCPAdapter(port: $port, resolver: $this->resolver);
            }

            if ($this->config->skipValidation) {
                $adapter->setSkipValidation(true);
            }

            if ($this->config->cacheTTL > 0) {
                $adapter->setCacheTTL($this->config->cacheTTL);
            }

            $adapter
                ->setTimeout($this->config->timeout)
                ->setConnectTimeout($this->config->connectTimeout)
                ->setTcpUserTimeout($this->config->tcpUserTimeoutMs)
                ->setTcpQuickAck($this->config->tcpQuickAck)
                ->setTcpNotsentLowat($this->config->tcpNotsentLowat)
                ->setSockmap($this->sockmap);

            $this->adapters[$port] = $adapter;
        }

        Console::log("Worker #{$workerId} started");
    }

    /**
     * Main receive handler
     *
     * When TLS is enabled, handles protocol-specific SSL negotiation:
     * - PostgreSQL: Intercepts SSLRequest, responds 'S', Swoole upgrades to TLS
     * - MySQL: Swoole handles SSL natively via SWOOLE_SSL socket type
     */
    public function onReceive(Server $server, int $fd, string $data, int $port): void
    {
        $connection = $this->connections[$fd] ?? null;

        // Fast path: existing connection - forward to appropriate backend.
        // When sockmap is active for this fd the kernel has already handled
        // the data before we ever saw it — onReceive shouldn't even fire —
        // but keep a defensive pass-through in case the verifier serves up
        // a packet that bypassed the redirect (e.g. partial tear-down).
        if ($connection !== null && $connection->backend !== null) {
            $adapter = $this->adapters[$connection->port] ?? null;
            if ($adapter !== null && $adapter->isSockmapActive($fd)) {
                return;
            }

            if ($connection->backend->send($data) === false) {
                $server->close($fd);
            }

            return;
        }

        if ($connection === null) {
            $connection = new Connection();
            $connection->port = $port;
            $this->connections[$fd] = $connection;
        }

        $sslResponse = $this->postgreSQLSSLResponse($data, $port);
        if ($sslResponse !== null) {
            $server->send($fd, $sslResponse);
            $connection->pendingTls = $sslResponse === TLS::PG_SSL_RESPONSE_OK;

            return;
        }

        $connection->pendingTls = false;

        try {
            $adapter = $this->adapters[$port] ?? null;
            if ($adapter === null) {
                throw new \Exception("No adapter registered for port {$port}");
            }

            // Route via resolver — the resolver receives raw initial data
            // and is responsible for extracting any routing information
            $backend = $adapter->getConnection($data, $fd);
            $connection->backend = $backend;

            // Forward initial handshake data to backend BEFORE activating
            // sockmap — once the pair is in the map, any send() on the
            // backend fd would be redirected back to the client by the
            // sk_msg program.
            $backend->send($adapter->getInitialData($fd, $data));

            // Activate kernel zero-copy relay. If successful the kernel
            // handles all subsequent bytes and we skip the userspace
            // forward loop entirely. Otherwise fall back to the coroutine
            // path as before.
            if (!$adapter->activateSockmap($fd)) {
                $this->forward($server, $fd, $backend);
            }

        } catch (\Exception $e) {
            Console::error("Error handling data from #{$fd}: {$e->getMessage()}");
            $server->close($fd);
        }
    }

    /**
     * Backend → client forwarding coroutine.
     *
     * The client → backend direction is driven by the reactor's onReceive
     * callback directly; only the backend → client side needs its own
     * read loop because the backend socket is not registered with the
     * server's reactor.
     */
    protected function forward(Server $server, int $clientFd, Client $backend): void
    {
        $bufferSize = $this->config->receiveBufferSize;
        $exported = $backend->exportSocket();
        if (!$exported instanceof Socket) {
            $server->close($clientFd);

            return;
        }
        $backendSocket = $exported;
        \go(static function () use ($server, $clientFd, $backendSocket, $bufferSize): void {
            while ($server->exist($clientFd)) {
                /** @var string|false $data */
                $data = $backendSocket->recv($bufferSize);
                if ($data === false || $data === '') {
                    break;
                }
                if ($server->send($clientFd, $data) === false) {
                    break;
                }
            }
            $server->close($clientFd);
        });
    }

    private function postgreSQLSSLResponse(string $data, int $port): ?string
    {
        if (!\in_array($port, [5432, 6432], true) || !TLS::isPostgreSQLSSLRequest($data)) {
            return null;
        }

        if ($this->tlsContext !== null) {
            return TLS::PG_SSL_RESPONSE_OK;
        }

        return TLS::PG_SSL_RESPONSE_REJECT;
    }

    public function onClose(Server $server, int $fd, int $reactorId): void
    {
        if ($this->config->logConnections) {
            Console::log("Client #{$fd} disconnected");
        }

        $connection = $this->connections[$fd] ?? null;
        if ($connection === null) {
            return;
        }

        unset($this->connections[$fd]);

        $adapter = $this->adapters[$connection->port] ?? null;
        if ($adapter !== null) {
            $adapter->closeConnection($fd);
        }

        $connection->reset();
    }

    public function start(): void
    {
        $this->server->start();
    }

}
