<?php

declare(strict_types=1);

namespace Evolve\Webhooks;

use InvalidArgumentException;

final class ResponsePolicy
{
    public static function classify(int $status): string
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('Invalid HTTP status.');
        }
        if ($status >= 200 && $status < 300) {
            return 'acknowledged';
        }
        if ($status === 429 || $status >= 500) {
            return 'retry';
        }
        return 'terminal';
    }
}
