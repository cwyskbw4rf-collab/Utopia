<?php

namespace Utopia\Proxy\Server\TCP\Swoole;

use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Server as CoroutineServer;
use Swoole\Coroutine\Server\Connection;
use Swoole\Coroutine\Socket;
use Swoole\Timer;
use Utopia\Console;
use Utopia\Proxy\Adapter\TCP as TCPAdapter;
use Utopia\Proxy\Dns;
use Utopia\Proxy\Resolver;
use Utopia\Proxy\Server\TCP\Config;
use Utopia\Proxy\Server\TCP\TLS;
use Utopia\Proxy\Server\TCP\TLSContext;

/**
 * High-performance TCP proxy server (Swoole Coroutine Implementation)
 *
 * Uses coroutine-native sockets throughout. The default TCP server
 * (Server/TCP/Swoole.php) in SWOOLE_BASE mode outperforms this variant
 * on most workloads — this one is kept for users who need coroutine-level
 * control over each connection (e.g. custom protocol state machines).
 *
 * Supports optional PostgreSQL TLS termination via the wire protocol
 * SSLRequest/SSLResponse handshake.
 *
 * Example:
 * ```php
 * $tls = new TLS(certificate: '/certs/server.crt', key: '/certs/server.key');
 * $config = new Config(ports: [5432, 3306], tls: $tls);
 * $server = new Coroutine($resolver, $config);
 * $server->start();
 * ```
 */
class Coroutine
{
    /** @var array<int, CoroutineServer> */
    protected array $servers = [];

    /** @var array<int, TCPAdapter> */
    protected array $adapters = [];

    protected int $connections = 0;

    protected Config $config;

    protected ?TLSContext $tlsContext = null;

    protected ?Resolver $resolver;

    protected ?int $gcTimer = null;

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

        $this->initAdapters();
        $this->configureServers();
    }

    protected function initAdapters(): void
    {
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
                ->setTcpNotsentLowat($this->config->tcpNotsentLowat);

            $this->adapters[$port] = $adapter;
        }
    }

    protected function configureServers(): void
    {
        SwooleCoroutine::set([
            'max_coroutine' => $this->config->maxCoroutine,
            'socket_buffer_size' => $this->config->socketBufferSize,
            'stack_size' => $this->config->coroutineStackSize,
            'log_level' => $this->config->logLevel,
        ]);

        foreach ($this->config->ports as $port) {
            // Database wire TLS is negotiated after plaintext protocol bytes.
            // A TLS listener would expect ClientHello first and close
            // PostgreSQL SSLRequest before PHP can respond with 'S'.
            $server = new CoroutineServer($this->config->host, $port, false, $this->config->enableReusePort);

            $settings = [
                'open_tcp_nodelay' => true,
                'open_tcp_keepalive' => true,
                'tcp_keepidle' => $this->config->tcpKeepidle,
                'tcp_keepinterval' => $this->config->tcpKeepinterval,
                'tcp_keepcount' => $this->config->tcpKeepcount,
                'open_length_check' => false,
                'package_max_length' => $this->config->packageMaxLength,
                'buffer_output_size' => $this->config->bufferOutputSize,
            ];

            $server->set($settings);

            $server->handle(function (Connection $connection) use ($port): void {
                $this->handleConnection($connection, $port);
            });

            $this->servers[$port] = $server;
        }
    }

    public function onStart(): void
    {
        Console::success("TCP Proxy Server started at {$this->config->host}");
        Console::log('Ports: '.\implode(', ', $this->config->ports));
        Console::log("Workers: {$this->config->workers}");
        Console::log("Max connections: {$this->config->maxConnections}");

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

    public function onWorkerStart(int $workerId = 0): void
    {
        \gc_disable();
        $this->gcTimer = Timer::tick($this->config->gcIntervalMs, static function (): void {
            \gc_collect_cycles();
        });

        Dns::setTtl($this->config->dnsCacheTtl);

        Console::log("Worker #{$workerId} started");
    }

    protected function handleConnection(Connection $connection, int $port): void
    {
        $this->connections++;

        try {
            $this->handleConnectionData($connection, $port);
        } finally {
            $this->connections--;
        }
    }

    protected function handleConnectionData(Connection $connection, int $port): void
    {
        /** @var Socket $clientSocket */
        $clientSocket = $connection->exportSocket();
        $clientId = \spl_object_id($connection);
        $adapter = $this->adapters[$port];
        $bufferSize = $this->config->receiveBufferSize;

        if ($this->config->logConnections) {
            Console::log("Client #{$clientId} connected to port {$port}");
        }

        /** @var string|false $data */
        $data = $clientSocket->recv($bufferSize);
        if ($data === false || $data === '') {
            $clientSocket->close();

            return;
        }

        if (\in_array($port, [5432, 6432], true) && TLS::isPostgreSQLSSLRequest($data)) {
            if ($this->tlsContext === null) {
                if ($clientSocket->sendAll(TLS::PG_SSL_RESPONSE_REJECT) === false) {
                    $clientSocket->close();

                    return;
                }
            } else {
                if ($clientSocket->sendAll(TLS::PG_SSL_RESPONSE_OK) === false || !$this->startTLS($clientSocket)) {
                    $clientSocket->close();

                    return;
                }
            }

            /** @var string|false $data */
            $data = $clientSocket->recv($bufferSize);
            if ($data === false || $data === '') {
                $clientSocket->close();

                return;
            }
        }

        $done = new Channel(1);

        try {
            $backendClient = $adapter->getConnection($data, $clientId);
        } catch (\Exception $e) {
            Console::error("Error handling data from #{$clientId}: {$e->getMessage()}");
            $clientSocket->close();

            return;
        }

        /** @var Socket $backendSocket */
        $backendSocket = $backendClient->exportSocket();

        \go(function () use ($clientSocket, $backendSocket, $bufferSize, $done): void {
            while (true) {
                /** @var string|false $data */
                $data = $backendSocket->recv($bufferSize);
                if ($data === false || $data === '') {
                    break;
                }
                if ($clientSocket->sendAll($data) === false) {
                    break;
                }
            }
            $done->push(true);
        });

        if ($backendSocket->sendAll($data) === false) {
            $backendSocket->close();
            $done->pop(1.0);
            $clientSocket->close();
            $adapter->closeConnection($clientId);

            return;
        }

        while (true) {
            /** @var string|false $data */
            $data = $clientSocket->recv($bufferSize);
            if ($data === false || $data === '') {
                break;
            }
            if ($backendSocket->sendAll($data) === false) {
                break;
            }
        }

        $backendSocket->close();
        $done->pop();
        $clientSocket->close();

        $adapter->closeConnection($clientId);

        if ($this->config->logConnections) {
            Console::log("Client #{$clientId} disconnected");
        }
    }

    /**
     * @return array{connection_num: int}
     */
    public function stats(): array
    {
        return [
            'connection_num' => $this->connections,
        ];
    }

    protected function startTLS(Socket $socket): bool
    {
        if ($this->tlsContext === null) {
            return false;
        }

        if (!$socket->setProtocol($this->tlsContext->toSwooleProtocolConfig())) {
            return false;
        }

        return $socket->sslHandshake();
    }

    public function start(): void
    {
        $runner = function (): void {
            $this->onStart();
            $this->onWorkerStart(0);

            foreach ($this->servers as $server) {
                \go(function () use ($server): void {
                    $server->start();
                });
            }
        };

        if (SwooleCoroutine::getCid() > 0) {
            $runner();

            return;
        }

        SwooleCoroutine\run($runner);
    }

    public function shutdown(): void
    {
        if ($this->gcTimer !== null) {
            Timer::clear($this->gcTimer);
            $this->gcTimer = null;
        }

        foreach ($this->servers as $server) {
            $server->shutdown();
        }
    }

    /**
     * Report whether the JIT is actually enabled inside this Swoole worker.
     *
     * PHP 8.3+ shipped a fix that lets OPcache's tracing JIT coexist with
     * Swoole's opcode handlers, but it silently stays off on older builds
     * or when opcache.enable_cli is 0. Logging the state at startup makes
     * JIT misconfiguration visible in ops.
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

}
