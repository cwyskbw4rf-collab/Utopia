<?php

namespace Utopia\Proxy\Server\TCP;

use Utopia\Validator\Text;

/**
 * TLS Configuration for TCP Proxy Server
 *
 * Holds certificate paths, protocol constraints, cipher configuration,
 * and mTLS (mutual TLS) settings for TLS-terminated TCP connections.
 *
 * Supports PostgreSQL STARTTLS via SSLRequest upgrade from plaintext.
 * MySQL-compatible protocols negotiate TLS after a server greeting, so
 * a generic client-first TCP router cannot terminate that handshake without
 * a protocol-aware MySQL frontend.
 *
 * Example:
 * ```php
 * $tls = new TLS(
 *     certificate: '/certs/server.crt',
 *     key: '/certs/server.key',
 *     ca: '/certs/ca.crt',
 *     requireClientCert: true,
 * );
 * $config = new Config(tls: $tls);
 * ```
 */
class TLS
{
    /**
     * PostgreSQL SSLRequest message (8 bytes):
     * - Int32(8): message length
     * - Int32(80877103): SSL request code
     */
    public const PG_SSL_REQUEST = "\x00\x00\x00\x08\x04\xd2\x16\x2f";

    /**
     * PostgreSQL SSLResponse: server willing to accept SSL
     */
    public const PG_SSL_RESPONSE_OK = 'S';

    /**
     * PostgreSQL SSLResponse: server unwilling to accept SSL
     */
    public const PG_SSL_RESPONSE_REJECT = 'N';

    /**
     * MySQL capability flag: CLIENT_SSL (0x00000800)
     */
    public const MYSQL_CLIENT_SSL_FLAG = 0x00000800;

    /**
     * Default cipher suites — strong, modern, broadly compatible
     */
    public const DEFAULT_CIPHERS = 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:'
        . 'ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:'
        . 'ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:'
        . 'DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384';

    /**
     * Minimum TLS protocol version (TLS 1.2).
     *
     * The numeric value matches SWOOLE_SSL_TLSv1_2 in Swoole's source and
     * is hardcoded here so the class loads on Swoole builds compiled
     * without OpenSSL support — the constant would otherwise be
     * undefined at class load time, before any TLS code runs.
     */
    public const MIN_TLS_VERSION = 32;

    public function __construct(
        public readonly string $certificate,
        public readonly string $key,
        public readonly string $ca = '',
        public readonly bool $requireClientCert = false,
        public readonly string $ciphers = self::DEFAULT_CIPHERS,
        public readonly int $minProtocol = self::MIN_TLS_VERSION,
    ) {
    }

    /**
     * Validate that the configured certificate files exist and are readable
     *
     * @throws \RuntimeException If any required file is missing or unreadable
     */
    public function validate(): void
    {
        $path = new Text(4096);

        if (!$path->isValid($this->certificate)) {
            throw new \RuntimeException("TLS certificate path is invalid: {$path->getDescription()}");
        }

        if (!is_readable($this->certificate)) {
            throw new \RuntimeException("TLS certificate file not readable: {$this->certificate}");
        }

        if (!$path->isValid($this->key)) {
            throw new \RuntimeException("TLS key path is invalid: {$path->getDescription()}");
        }

        if (!is_readable($this->key)) {
            throw new \RuntimeException("TLS private key file not readable: {$this->key}");
        }

        if ($this->requireClientCert && $this->ca === '') {
            throw new \RuntimeException('CA certificate path is required when client certificate verification is enabled');
        }

        if ($this->ca !== '' && !$path->isValid($this->ca)) {
            throw new \RuntimeException("TLS CA path is invalid: {$path->getDescription()}");
        }

        if ($this->ca !== '' && !is_readable($this->ca)) {
            throw new \RuntimeException("TLS CA certificate file not readable: {$this->ca}");
        }
    }

    /**
     * Check if this is an mTLS configuration (requires client certificates)
     */
    public function isMutual(): bool
    {
        return $this->requireClientCert && $this->ca !== '';
    }

    /**
     * Detect whether a raw data packet is a PostgreSQL SSLRequest message
     *
     * The SSLRequest is exactly 8 bytes:
     * - Int32(8): length
     * - Int32(80877103): SSL request code (0x04D2162F)
     */
    public static function isPostgreSQLSSLRequest(string $data): bool
    {
        return strlen($data) === 8 && $data === self::PG_SSL_REQUEST;
    }

    /**
     * Detect whether a raw data packet is a MySQL SSL handshake request
     *
     * After receiving the server greeting with SSL capability flag,
     * the client sends an SSL request packet. This is identified by:
     * - Packet length >= 4 bytes (header)
     * - Capability flags in bytes 4-7 include CLIENT_SSL (0x0800)
     * - Sequence ID = 1 (byte 3)
     */
    public static function isMySQLSSLRequest(string $data): bool
    {
        if (strlen($data) < 36) {
            return false;
        }

        // Sequence ID should be 1 (client response to server greeting)
        if (ord($data[3]) !== 1) {
            return false;
        }

        // Read capability flags (little-endian uint16 at offset 4)
        $capLow = ord($data[4]) | (ord($data[5]) << 8);

        return ($capLow & self::MYSQL_CLIENT_SSL_FLAG) !== 0;
    }
}
