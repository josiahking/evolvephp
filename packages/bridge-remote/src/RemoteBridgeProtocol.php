<?php

declare(strict_types=1);

namespace Evolve\Bridge\Remote;

/**
 * @experimental
 */
final class RemoteBridgeProtocol
{
    public const int VERSION = 1;
    public const string MEDIA_TYPE = 'application/vnd.evolve.bridge.remote.v1+json';
    public const string HTTP_METHOD = 'POST';
    public const int MAX_BODY_BYTES = 65536;
    public const array FORWARDED_REQUEST_HEADERS = [
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
        $mediaType = strtolower(trim(explode(';', $value, 2)[0]));

        return $mediaType === self::MEDIA_TYPE || $mediaType === 'application/json';
    }

    public static function headerIsForwardable(string $name): bool
    {
        return in_array(strtolower($name), self::FORWARDED_REQUEST_HEADERS, true);
    }
}
