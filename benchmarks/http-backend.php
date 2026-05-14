<?php

$host = getenv('BACKEND_HOST') ?: '127.0.0.1';
$port = (int) (getenv('BACKEND_PORT') ?: 5678);
$workers = (int) (getenv('BACKEND_WORKERS') ?: (function_exists('swoole_cpu_num') ? swoole_cpu_num() : 4));

// Retry the bind up to 10x in case the port is in TIME_WAIT from a prior run.
// Swoole's enable_reuse_port doesn't help with server-side TIME_WAIT reuse;
// we just wait it out rather than depend on kernel tw_reuse tuning.
$server = null;
for ($attempt = 1; $attempt <= 10; $attempt++) {
    try {
        $server = new Swoole\Http\Server($host, $port, SWOOLE_PROCESS);
        break;
    } catch (\Throwable $e) {
        if ($attempt === 10) {
            throw $e;
        }
        fwrite(STDERR, "[http-backend] bind attempt $attempt failed: " . $e->getMessage() . " -- waiting 6s\n");
        sleep(6);
    }
}

$server->set([
    'worker_num' => $workers,
    'max_connection' => 200_000,
    'max_coroutine' => 200_000,
    'enable_coroutine' => true,
    'open_tcp_nodelay' => true,
    'tcp_fastopen' => true,
    'enable_reuse_port' => true,
    'open_cpu_affinity' => true,
    'log_level' => SWOOLE_LOG_ERROR,
]);

$server->on('request', static function (Swoole\Http\Request $request, Swoole\Http\Response $response): void {
    $response->header('Content-Type', 'text/plain');
    $response->end('ok');
});

$server->start();
