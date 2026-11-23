<?php

declare(strict_types=1);

namespace Evolve\Webhooks;

use InvalidArgumentException;

final class DestinationPolicy
{
    public static function assertSafeUrl(string $url): string
    {
        if (strlen($url) > 2048 || preg_match('/[\\x00-\\x20\\x7f]/', $url) === 1 || str_contains($url, chr(92))) {
            throw new InvalidArgumentException('Unsafe webhook destination.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || ($parts['port'] ?? 443) !== 443) {
            throw new InvalidArgumentException('Unsafe webhook destination.');
        }
        $host = strtolower($parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/\A(?=.{1,253}\z)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/D', $host) !== 1
            || !str_contains($host, '.') || str_contains($host, '..')) {
            throw new InvalidArgumentException('Unsafe webhook destination.');
        }
        return $url;
    }
}
