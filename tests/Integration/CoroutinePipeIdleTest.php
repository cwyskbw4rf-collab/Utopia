<?php

namespace Utopia\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use Utopia\Proxy\Resolver\Fixed;
use Utopia\Proxy\Server\TCP\Config;
use Utopia\Proxy\Server\TCP\Swoole\Coroutine as CoroutineServer;

/**
 * Regression test for the pipe-loop timeout bug: relay recv() calls with no
 * timeout argument inherit the socket's read timeout. The backend client
 * socket gets the connect timeout re-stamped as its read timeout by
 * Client::connect(), so recv() returned false (ETIMEDOUT) after a few idle
 * seconds — indistinguishable from a closed peer — and the backend→client
 * relay silently died on idle long-lived sessions.
 *
 * The fix reads with recv($bufferSize, -1) and relies on TCP keepalive and
 * FIN/RST for dead-peer detection.
 *
 * @group integration
 */
class CoroutinePipeIdleTest extends TestCase
{
    protected function setUp(): void
    {
        if (! \extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required.');
        }
    }

    public function testBackendRelaySurvivesIdleBeyondConnectTimeout(): void
    {
        $received = [];
        $error = null;

        Coroutine\run(function () use (&$received, &$error): void {
            $server = null;
            $client = null;

            try {
                // Backend that answers, goes idle for longer than the proxy's
                // connect/read timeouts, then sends a delayed payload.
                $listener = new Socket(\AF_INET, \SOCK_STREAM, 0);
                $listener->bind('127.0.0.1', 0);
                $listener->listen(128);

                /** @var array{port: int} $address */
                $address = $listener->getsockname();
                $backendPort = $address['port'];

                Coroutine::create(function () use ($listener): void {
                    /** @var Socket $peer */
                    $peer = $listener->accept(5.0);
                    $peer->recv(4096, 5.0);
                    $peer->send('first');
                    Coroutine::sleep(1.0);
                    $peer->send('second');
                    $peer->recv(4096, 5.0);
                    $peer->close();
                    $listener->close();
                });

                [$server, $proxyPort] = $this->startProxy($backendPort);
                $server->start();
                Coroutine::sleep(0.05);

                $client = new Socket(\AF_INET, \SOCK_STREAM, 0);
                $this->assertTrue($client->connect('127.0.0.1', $proxyPort, 2.0));
                $this->assertNotFalse($client->send('init'));

                $received[] = $client->recv(4096, 2.0);

                // The backend stays silent for 1.0s here — longer than the
                // 0.4s timeouts. Without the fix the backend→client relay
                // breaks during this idle window and 'second' never arrives.
                $received[] = $client->recv(4096, 3.0);
            } catch (\Throwable $e) {
                $error = $e;
            } finally {
                $client?->close();
                $server?->shutdown();
            }
        });

        if ($error !== null) {
            throw $error;
        }

        $this->assertSame('first', $received[0] ?? null);
        $this->assertSame('second', $received[1] ?? null);
    }

    /**
     * Bind the proxy on a random port, retrying on collision.
     *
     * @return array{0: CoroutineServer, 1: int}
     */
    private function startProxy(int $backendPort): array
    {
        $resolver = new Fixed("127.0.0.1:{$backendPort}");
        $attempts = 0;

        while (true) {
            $proxyPort = \random_int(20_000, 60_000);

            $config = new Config(
                ports: [$proxyPort],
                host: '127.0.0.1',
                timeout: 0.4,
                connectTimeout: 0.4,
                skipValidation: true,
            );

            try {
                return [new CoroutineServer($resolver, $config), $proxyPort];
            } catch (\Swoole\Exception $e) {
                if (++$attempts >= 10) {
                    throw $e;
                }
            }
        }
    }
}
