<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Cross-language fixture test. The shared JSON fixture encodes the exact 8-byte
 * tuple key expected by both the PHP `Loader::tupleKey()` implementation and the
 * Rust `sockmap::tuple::tuple_key` function. Both implementations are pure
 * bit-arithmetic once the sockaddr bytes are in hand, so we reproduce that
 * arithmetic here to keep the assertion hermetic (no real sockets required).
 *
 * If this test ever disagrees with `rust/crates/utopia-proxy/tests/sockmap_key.rs`,
 * one of the two implementations drifted and the BPF map will lookup stale keys.
 */
class SockmapKeyTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__ . '/../rust/crates/utopia-proxy/tests/fixtures/sockmap_keys.json';

    public function testFixtureMatchesPhpTupleKeyMath(): void
    {
        $this->assertFileExists(self::FIXTURE_PATH);
        $json = \file_get_contents(self::FIXTURE_PATH);
        $this->assertNotFalse($json);

        $cases = \json_decode($json, true);
        $this->assertIsArray($cases);
        $this->assertGreaterThanOrEqual(4, \count($cases));

        foreach ($cases as $case) {
            $this->assertIsArray($case);
            /** @var array{name: string, local_port: int, remote_port_be: array<int>, remote_ip_be: array<int>, expected_be: array<int>} $case */
            $name = (string) $case['name'];
            $localPort = (int) $case['local_port'];
            $remotePortBe = $case['remote_port_be'];
            $remoteIpBe = $case['remote_ip_be'];
            $expectedBe = $case['expected_be'];

            $rportBytes = \pack('C*', ...$remotePortBe);
            $ripBytes = \pack('C*', ...$remoteIpBe);
            $rportUnpacked = \unpack('v', $rportBytes);
            $ripUnpacked = \unpack('V', $ripBytes);

            $this->assertIsArray($rportUnpacked);
            $this->assertIsArray($ripUnpacked);
            $this->assertIsInt($rportUnpacked[1]);
            $this->assertIsInt($ripUnpacked[1]);

            $rport = $rportUnpacked[1];
            $rip = $ripUnpacked[1];

            $key = ($localPort & 0xffff) << 48;
            $key |= ($rport & 0xffff) << 32;
            $key |= $rip & 0xffffffff;

            // Convert the 64-bit key to 8 big-endian bytes to compare.
            $bytes = [];
            for ($i = 7; $i >= 0; $i--) {
                $bytes[] = ($key >> ($i * 8)) & 0xff;
            }

            $this->assertSame(
                $expectedBe,
                $bytes,
                "tuple key mismatch for case '{$name}'"
            );
        }
    }
}
