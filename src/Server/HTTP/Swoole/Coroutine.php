<?php

namespace Utopia\Proxy\Server\HTTP\Swoole;

use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Coroutine\Http\Server as CoroutineServer;
use Utopia\Console;
use Utopia\Proxy\Adapter;
use Utopia\Proxy\Protocol;
use Utopia\Proxy\Resolver;
use Utopia\Proxy\Server\HTTP\Config;

/**
 * High-performance HTTP proxy server (Swoole Coroutine Implementation)
 *
 * Example:
 * ```php
 * $resolver = new MyFunctionResolver();
 * $server = new Coroutine($resolver, new Config(host: '0.0.0.0', port: 80));
 * $server->start();
 * ```
 */
class Coroutine
{
    use Handler;

    protected CoroutineServer $server;

    public function __construct(
        protected ?Resolver $resolver = null,
        ?Config $config = null,
    ) {
        $this->config = $config ?? new Config();
        $this->initAdapter();
        $this->server = new CoroutineServer(
            $this->config->host,
            $this->config->port,
            false,
            $this->config->enableReusePort,
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
        $this->server->handle('/', $this->onRequest(...));
    }

    protected function initAdapter(): void
    {
        $this->adapter = new Adapter($this->resolver, protocol: Protocol::HTTP);
        $this->adapter->setCacheTTL($this->config->cacheTTL);

        if ($this->config->skipValidation) {
            $this->adapter->setSkipValidation(true);
        }
    }

    public function onStart(): void
    {
        Console::success("HTTP Proxy Server started at http://{$this->config->host}:{$this->config->port}");
        Console::log("Workers: {$this->config->workers}");
        Console::log("Max connections: {$this->config->maxConnections}");
    }

    public function onWorkerStart(int $workerId = 0): void
    {
        if ($this->config->workerStart !== null) {
            ($this->config->workerStart)(null, $workerId, $this->adapter);
        }

        Console::log("Worker #{$workerId} started ({$this->adapter->getProtocol()->value})");
    }

    public function start(): void
    {
        if (SwooleCoroutine::getCid() > 0) {
            $this->onStart();
            $this->onWorkerStart(0);
            $this->server->start();

            return;
        }

        SwooleCoroutine\run(function (): void {
            $this->onStart();
            $this->onWorkerStart(0);
            $this->server->start();
        });
    }

}
