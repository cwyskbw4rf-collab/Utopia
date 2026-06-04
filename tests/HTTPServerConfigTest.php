<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Proxy\Server\HTTP\Config;
use Utopia\Proxy\Server\HTTP\Swoole;
use Utopia\Proxy\Server\HTTP\Swoole\Coroutine;

class HTTPServerConfigTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run HTTP server config tests.');
        }
    }

    public function testEventServerConfiguresWithoutSwooleWarnings(): void
    {
        $warnings = $this->captureWarnings(function (): void {
            new Swoole(config: new Config(port: 0, workers: 1));
        });

        $this->assertSame([], $warnings);
    }

    public function testCoroutineServerConfiguresWithoutSwooleWarnings(): void
    {
        $warnings = $this->captureWarnings(function (): void {
            new Coroutine(config: new Config(port: 0, workers: 1));
        });

        $this->assertSame([], $warnings);
    }

    /**
     * @return list<string>
     */
    private function captureWarnings(\Closure $action): array
    {
        $warnings = [];

        \set_error_handler(function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $action();
        } finally {
            \restore_error_handler();
        }

        return $warnings;
    }
}
