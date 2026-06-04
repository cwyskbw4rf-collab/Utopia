<?php

namespace Utopia\Proxy\Server\HTTP;

use Swoole\Constant;
use Swoole\Http\Server;
use Utopia\Console;
use Utopia\Proxy\Adapter;
use Utopia\Proxy\Protocol;
use Utopia\Proxy\Resolver;
use Utopia\Proxy\Server\HTTP\Swoole\Handler;

/**
 * High-performance HTTP proxy server (Swoole Implementation)
 *
 * Example:
 * ```php
 * $resolver = new MyFunctionResolver();
 * $server = new Swoole($resolver, new Config(host: '0.0.0.0', port: 80));
 * $server->start();
 * ```
 */
class Swoole
{
    use Handler;

    protected Server $server;

    public function __construct(
        protected ?Resolver $resolver = null,
        ?Config $config = null,
    ) {
        $this->config = $config ?? new Config();
        $this->server = new Server(
            $this->config->host,
            $this->config->port,
            $this->config->serverMode,
        );
        $this->configure();
    }

    protected function configure(): void
    {
        $this->server->set([
            'worker_num' => $this->config->workers,
            'reactor_num' => $this->config->reactorNum,
            'max_connection' => $this->config->maxConnections,
            'max_coroutine' => $this->config->maxCoroutine,
            'socket_buffer_size' => $this->config->socketBufferSize,
            'buffer_output_size' => $this->config->bufferOutputSize,
            'enable_coroutine' => $this->config->enableCoroutine,
            'max_wait_time' => $this->config->maxWaitTime,
            'open_http_protocol' => $this->config->httpProtocol,
            'open_http2_protocol' => $this->config->http2Protocol,
            'max_request' => $this->config->maxRequest,
            'dispatch_mode' => $this->config->dispatchMode,
            'enable_reuse_port' => $this->config->enableReusePort,
            'backlog' => $this->config->backlog,
            'http_parse_post' => $this->config->parsePost,
            'http_parse_cookie' => $this->config->parseCookie,
            'http_parse_files' => $this->config->parseFiles,
            'http_compression' => $this->config->compression,
            'log_level' => $this->config->logLevel,
            'open_tcp_nodelay' => true,
            'tcp_fastopen' => true,
            'open_cpu_affinity' => true,
            'tcp_defer_accept' => 5,
            'task_enable_coroutine' => true,
        ]);

        $this->server->on(Constant::EVENT_START, $this->onStart(...));
        $this->server->on(Constant::EVENT_WORKER_START, $this->onWorkerStart(...));
        $this->server->on(Constant::EVENT_REQUEST, $this->onRequest(...));
    }

    public function onStart(Server $server): void
    {
        Console::success("HTTP Proxy Server started at http://{$this->config->host}:{$this->config->port}");
        Console::log("Workers: {$this->config->workers}");
        Console::log("Max connections: {$this->config->maxConnections}");
    }

    public function onWorkerStart(Server $server, int $workerId): void
    {
        $this->adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);
        $this->adapter->setCacheTTL($this->config->cacheTTL);

        if ($this->config->skipValidation) {
            $this->adapter->setSkipValidation(true);
        }

        if ($this->config->workerStart !== null) {
            ($this->config->workerStart)($server, $workerId, $this->adapter);
        }

        Console::log("Worker #{$workerId} started ({$this->adapter->getProtocol()->value})");
    }

    public function start(): void
    {
        $this->server->start();
    }

}
