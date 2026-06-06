<?php

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Utopia\Proxy\Server\TCP\Swoole as TCPServer;
use Utopia\Proxy\Server\TCP\TLS;

class TLSTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('swoole')) {
            $this->markTestSkipped('ext-swoole is required to run TLS tests.');
        }
        if (!\defined('SWOOLE_SSL_TLSv1_2')) {
            $this->markTestSkipped('Swoole was built without OpenSSL support.');
        }
    }

    public function testConstructorSetsRequiredPaths(): void
    {
        $tls = new TLS(certificate: '/certs/server.crt', key: '/certs/server.key');

        $this->assertSame('/certs/server.crt', $tls->certificate);
        $this->assertSame('/certs/server.key', $tls->key);
    }

    public function testConstructorDefaultValues(): void
    {
        $tls = new TLS(certificate: '/certs/server.crt', key: '/certs/server.key');

        $this->assertSame('', $tls->ca);
        $this->assertFalse($tls->requireClientCert);
        $this->assertSame(TLS::DEFAULT_CIPHERS, $tls->ciphers);
        $this->assertSame(TLS::MIN_TLS_VERSION, $tls->minProtocol);
    }

    public function testConstructorCustomValues(): void
    {
        $tls = new TLS(
            certificate: '/certs/server.crt',
            key: '/certs/server.key',
            ca: '/certs/ca.crt',
            requireClientCert: true,
            ciphers: 'ECDHE-RSA-AES128-GCM-SHA256',
            minProtocol: SWOOLE_SSL_TLSv1_3,
        );

        $this->assertSame('/certs/ca.crt', $tls->ca);
        $this->assertTrue($tls->requireClientCert);
        $this->assertSame('ECDHE-RSA-AES128-GCM-SHA256', $tls->ciphers);
        $this->assertSame(SWOOLE_SSL_TLSv1_3, $tls->minProtocol);
    }

    public function testPgSslRequestConstant(): void
    {
        $this->assertSame(8, strlen(TLS::PG_SSL_REQUEST));
        // Verify SSL request code bytes: 0x04D2162F = 80877103
        $this->assertSame("\x00\x00\x00\x08\x04\xd2\x16\x2f", TLS::PG_SSL_REQUEST);
    }

    public function testPgSslResponseConstants(): void
    {
        $this->assertSame('S', TLS::PG_SSL_RESPONSE_OK);
        $this->assertSame('N', TLS::PG_SSL_RESPONSE_REJECT);
    }

    public function testMySqlSslFlagConstant(): void
    {
        $this->assertSame(0x00000800, TLS::MYSQL_CLIENT_SSL_FLAG);
    }

    public function testDefaultCiphersContainsModernSuites(): void
    {
        $this->assertStringContainsString('ECDHE-ECDSA-AES128-GCM-SHA256', TLS::DEFAULT_CIPHERS);
        $this->assertStringContainsString('ECDHE-RSA-AES256-GCM-SHA384', TLS::DEFAULT_CIPHERS);
        $this->assertStringContainsString('CHACHA20-POLY1305', TLS::DEFAULT_CIPHERS);
    }

    public function testValidatePassesWithReadableFiles(): void
    {
        $certFile = tempnam(sys_get_temp_dir(), 'cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'key_');

        try {
            $tls = new TLS(certificate: $certFile, key: $keyFile);
            $tls->validate();
            $this->addToAssertionCount(1);
        } finally {
            unlink($certFile);
            unlink($keyFile);
        }
    }

    public function testValidateThrowsForUnreadableCert(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TLS certificate file not readable');

        $tls = new TLS(certificate: '/nonexistent/cert.crt', key: '/tmp/key.key');
        $tls->validate();
    }

    public function testValidateThrowsForUnreadableKey(): void
    {
        $certFile = tempnam(sys_get_temp_dir(), 'cert_');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('TLS private key file not readable');

            $tls = new TLS(certificate: $certFile, key: '/nonexistent/key.key');
            $tls->validate();
        } finally {
            unlink($certFile);
        }
    }

    public function testValidateThrowsWhenClientCertRequiredButNoCaPath(): void
    {
        $certFile = tempnam(sys_get_temp_dir(), 'cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'key_');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('CA certificate path is required when client certificate verification is enabled');

            $tls = new TLS(
                certificate: $certFile,
                key: $keyFile,
                requireClientCert: true,
            );
            $tls->validate();
        } finally {
            unlink($certFile);
            unlink($keyFile);
        }
    }

    public function testValidateThrowsForUnreadableCaFile(): void
    {
        $certFile = tempnam(sys_get_temp_dir(), 'cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'key_');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('TLS CA certificate file not readable');

            $tls = new TLS(
                certificate: $certFile,
                key: $keyFile,
                ca: '/nonexistent/ca.crt',
            );
            $tls->validate();
        } finally {
            unlink($certFile);
            unlink($keyFile);
        }
    }

    public function testValidatePassesWithAllReadableFiles(): void
    {
        $certFile = tempnam(sys_get_temp_dir(), 'cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'key_');
        $caFile = tempnam(sys_get_temp_dir(), 'ca_');

        try {
            $tls = new TLS(
                certificate: $certFile,
                key: $keyFile,
                ca: $caFile,
                requireClientCert: true,
            );
            $tls->validate();
            $this->addToAssertionCount(1);
        } finally {
            unlink($certFile);
            unlink($keyFile);
            unlink($caFile);
        }
    }

    public function testValidateCaPathOptionalWithoutClientCert(): void
    {
        $certFile = tempnam(sys_get_temp_dir(), 'cert_');
        $keyFile = tempnam(sys_get_temp_dir(), 'key_');

        try {
            // ca is empty and requireClientCert is false — should pass
            $tls = new TLS(certificate: $certFile, key: $keyFile);
            $tls->validate();
            $this->addToAssertionCount(1);
        } finally {
            unlink($certFile);
            unlink($keyFile);
        }
    }

    public function testIsMutualTLSReturnsTrueWhenBothConditionsMet(): void
    {
        $tls = new TLS(
            certificate: '/certs/server.crt',
            key: '/certs/server.key',
            ca: '/certs/ca.crt',
            requireClientCert: true,
        );

        $this->assertTrue($tls->isMutual());
    }

    public function testIsMutualTLSReturnsFalseWhenClientCertNotRequired(): void
    {
        $tls = new TLS(
            certificate: '/certs/server.crt',
            key: '/certs/server.key',
            ca: '/certs/ca.crt',
            requireClientCert: false,
        );

        $this->assertFalse($tls->isMutual());
    }

    public function testIsMutualTLSReturnsFalseWhenCaPathEmpty(): void
    {
        $tls = new TLS(
            certificate: '/certs/server.crt',
            key: '/certs/server.key',
            requireClientCert: true,
        );

        $this->assertFalse($tls->isMutual());
    }

    public function testIsMutualTLSReturnsFalseWithDefaults(): void
    {
        $tls = new TLS(certificate: '/certs/server.crt', key: '/certs/server.key');

        $this->assertFalse($tls->isMutual());
    }

    public function testIsPostgreSQLSSLRequestWithValidData(): void
    {
        $this->assertTrue(TLS::isPostgreSQLSSLRequest(TLS::PG_SSL_REQUEST));
    }

    public function testIsPostgreSQLSSLRequestWithTooShortData(): void
    {
        $this->assertFalse(TLS::isPostgreSQLSSLRequest("\x00\x00\x00\x08\x04\xd2\x16"));
    }

    public function testIsPostgreSQLSSLRequestWithTooLongData(): void
    {
        $this->assertFalse(TLS::isPostgreSQLSSLRequest(TLS::PG_SSL_REQUEST . "\x00"));
    }

    public function testIsPostgreSQLSSLRequestWithEmptyData(): void
    {
        $this->assertFalse(TLS::isPostgreSQLSSLRequest(''));
    }

    public function testIsPostgreSQLSSLRequestWithWrongBytes(): void
    {
        $this->assertFalse(TLS::isPostgreSQLSSLRequest("\x00\x00\x00\x08\x00\x00\x00\x00"));
    }

    public function testIsPostgreSQLSSLRequestWithRegularStartupMessage(): void
    {
        // A regular PostgreSQL startup message (protocol version 3.0)
        $startup = "\x00\x00\x00\x08\x00\x03\x00\x00";
        $this->assertFalse(TLS::isPostgreSQLSSLRequest($startup));
    }

    public function testPostgreSQLSSLRequestIsRejectedWhenTlsIsDisabled(): void
    {
        $server = $this->tcpServerWithoutConstructor();

        $this->assertSame(TLS::PG_SSL_RESPONSE_REJECT, $this->postgreSQLSSLResponse($server, TLS::PG_SSL_REQUEST, 5432));
        $this->assertSame(TLS::PG_SSL_RESPONSE_REJECT, $this->postgreSQLSSLResponse($server, TLS::PG_SSL_REQUEST, 6432));
    }

    public function testPostgreSQLSSLResponseIgnoresNonSslPackets(): void
    {
        $server = $this->tcpServerWithoutConstructor();

        $startup = "\x00\x00\x00\x08\x00\x03\x00\x00";

        $this->assertNull($this->postgreSQLSSLResponse($server, $startup, 5432));
        $this->assertNull($this->postgreSQLSSLResponse($server, TLS::PG_SSL_REQUEST, 3306));
    }

    public function testIsMySQLSSLRequestWithValidData(): void
    {
        // Build a valid MySQL SSL request: 36+ bytes, sequence ID 1, SSL flag set
        $data = str_repeat("\x00", 36);
        $data[3] = "\x01"; // sequence ID = 1
        // Set CLIENT_SSL flag (0x0800) at offset 4-5 (little-endian)
        $data[4] = "\x00";
        $data[5] = "\x08"; // 0x0800 in little-endian
        $this->assertTrue(TLS::isMySQLSSLRequest($data));
    }

    public function testIsMySQLSSLRequestWithTooShortData(): void
    {
        $this->assertFalse(TLS::isMySQLSSLRequest(str_repeat("\x00", 35)));
    }

    public function testIsMySQLSSLRequestWithEmptyData(): void
    {
        $this->assertFalse(TLS::isMySQLSSLRequest(''));
    }

    public function testIsMySQLSSLRequestWithWrongSequenceId(): void
    {
        $data = str_repeat("\x00", 36);
        $data[3] = "\x02"; // sequence ID = 2 (should be 1)
        $data[4] = "\x00";
        $data[5] = "\x08";
        $this->assertFalse(TLS::isMySQLSSLRequest($data));
    }

    public function testIsMySQLSSLRequestWithoutSslFlag(): void
    {
        $data = str_repeat("\x00", 36);
        $data[3] = "\x01"; // sequence ID = 1
        // No SSL flag
        $data[4] = "\x00";
        $data[5] = "\x00";
        $this->assertFalse(TLS::isMySQLSSLRequest($data));
    }

    public function testIsMySQLSSLRequestWithSslFlagAndOtherFlags(): void
    {
        $data = str_repeat("\x00", 36);
        $data[3] = "\x01"; // sequence ID = 1
        // SSL flag (0x0800) combined with other flags (0xFF)
        $data[4] = "\xFF";
        $data[5] = "\x0F"; // includes 0x0800
        $this->assertTrue(TLS::isMySQLSSLRequest($data));
    }

    public function testIsMySQLSSLRequestWithSequenceIdZero(): void
    {
        $data = str_repeat("\x00", 36);
        $data[3] = "\x00"; // sequence ID = 0
        $data[4] = "\x00";
        $data[5] = "\x08";
        $this->assertFalse(TLS::isMySQLSSLRequest($data));
    }

    public function testIsMySQLSSLRequestWithExactly36Bytes(): void
    {
        $data = str_repeat("\x00", 36);
        $data[3] = "\x01";
        $data[4] = "\x00";
        $data[5] = "\x08";
        $this->assertTrue(TLS::isMySQLSSLRequest($data));
    }

    public function testIsMySQLSSLRequestWithLargerPacket(): void
    {
        $data = str_repeat("\x00", 100);
        $data[3] = "\x01";
        $data[4] = "\x00";
        $data[5] = "\x08";
        $this->assertTrue(TLS::isMySQLSSLRequest($data));
    }

    private function tcpServerWithoutConstructor(): TCPServer
    {
        $reflection = new ReflectionClass(TCPServer::class);
        $server = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('tlsContext');
        $property->setValue($server, null);

        return $server;
    }

    private function postgreSQLSSLResponse(TCPServer $server, string $data, int $port): ?string
    {
        $reflection = new ReflectionClass(TCPServer::class);
        $method = $reflection->getMethod('postgreSQLSSLResponse');
        $response = $method->invoke($server, $data, $port);

        $this->assertTrue(\is_string($response) || $response === null);

        return $response;
    }
}
