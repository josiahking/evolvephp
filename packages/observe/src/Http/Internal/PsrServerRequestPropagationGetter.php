<?php

declare(strict_types=1);

namespace Evolve\Observe\Http\Internal;

use OpenTelemetry\Context\Propagation\PropagationGetterInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PsrServerRequestPropagationGetter implements PropagationGetterInterface
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * @param mixed $carrier
     */
    public function keys($carrier): array
    {
        if (!$carrier instanceof ServerRequestInterface) {
            return [];
        }

        return array_map('strtolower', array_keys($carrier->getHeaders()));
    }

    /**
     * @param mixed $carrier
     */
    public function get($carrier, string $key): ?string
    {
        if (!$carrier instanceof ServerRequestInterface || !$carrier->hasHeader($key)) {
            return null;
        }

        $value = $carrier->getHeaderLine($key);

        return $value === '' ? null : $value;
    }
}
