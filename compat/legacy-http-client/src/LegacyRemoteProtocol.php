<?php

declare(strict_types=1);

namespace Evolve\Bridge\LegacyHttp;

final class LegacyRemoteProtocol
{
    public const VERSION = 1;
    public const MEDIA_TYPE = 'application/vnd.evolve.bridge.remote.v1+json';
    public const HTTP_METHOD = 'POST';
    public const MAX_BODY_BYTES = 65536;

    private const FORWARDED_REQUEST_HEADERS = [
        'accept',
        'content-type',
        'traceparent',
        'tracestate',
        'x-correlation-id',
        'x-idempotency-key',
        'x-request-id',
    ];

    private function __construct() {}

    public static function mediaTypeMatches(string $value): bool
    {
        $parts = explode(';', (string) $value, 2);
        $mediaType = strtolower(trim($parts[0]));

        return $mediaType === self::MEDIA_TYPE;
    }

    public static function invocationHeaderIsForwardable(string $name): bool
    {
        return in_array(strtolower((string) $name), self::FORWARDED_REQUEST_HEADERS, true);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function assertProtocolIdentifier(array $data): void
    {
        if (!array_key_exists('protocol', $data) || $data['protocol'] !== self::MEDIA_TYPE) {
            throw new \InvalidArgumentException('Remote Bridge protocol identifier is not supported.');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function assertProtocolVersion(array $data): void
    {
        if (!array_key_exists('version', $data) || $data['version'] !== self::VERSION) {
            throw new \InvalidArgumentException('Remote Bridge protocol version is not supported.');
        }
    }
}
