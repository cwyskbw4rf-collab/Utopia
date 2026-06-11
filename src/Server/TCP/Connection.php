<?php

namespace Utopia\Proxy\Server\TCP;

use Swoole\Coroutine\Client;
use Swoole\Coroutine\Socket;

/**
 * Per-connection state struct.
 *
 * One instance lives in an SplFixedArray slot keyed by file descriptor while
 * a client is connected. Replaces the previous map of three independent
 * associative arrays (backends and ports) with a single cache-line
 * friendly object lookup.
 */
class Connection
{
    public ?Client $backend = null;

    /**
     * Socket exported from the backend Client for the forward coroutine.
     * Kept here so onClose can close it directly — after exportSocket()
     * the Client no longer owns the fd, so Client::close() alone cannot
     * unblock a coroutine parked on an untimed recv().
     */
    public ?Socket $backendSocket = null;

    public int $port = 0;

    public int $inbound = 0;

    public int $outbound = 0;

    public function reset(): void
    {
        $this->backend = null;
        $this->backendSocket = null;
        $this->port = 0;
        $this->inbound = 0;
        $this->outbound = 0;
    }
}
