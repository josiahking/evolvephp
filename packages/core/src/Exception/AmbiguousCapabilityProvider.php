<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use Evolve\Contracts\Component\CapabilityIdentifier;
use Evolve\Contracts\Component\ComponentIdentifier;
use InvalidArgumentException;

/**
 * Raised when an ExactlyOne capability has multiple active providers without selection.
 *
 * @experimental
 */
final class AmbiguousCapabilityProvider extends ComponentGraphResolutionFailed
{
    /**
     * @var list<ComponentIdentifier>
     */
    private array $providers;

    /**
     * @param list<ComponentIdentifier> $providers
     */
    public function __construct(
        private ComponentIdentifier $consumer,
        private CapabilityIdentifier $capability,
        array $providers,
    ) {
        self::assertProviders($providers);

        $this->providers = $providers;

        parent::__construct('Capability provider is ambiguous.');
    }

    /**
     * @param array<mixed> $providers
     */
    private static function assertProviders(array $providers): void
    {
        if (!array_is_list($providers)) {
            throw new InvalidArgumentException('Ambiguous providers must be a list.');
        }

        $previous = null;

        foreach ($providers as $provider) {
            if (!$provider instanceof ComponentIdentifier) {
                throw new InvalidArgumentException('Ambiguous providers must contain component identifiers.');
            }

            if ($previous !== null && strcmp($previous, $provider->value()) >= 0) {
                throw new InvalidArgumentException('Ambiguous providers must be in lexical identifier order.');
            }

            $previous = $provider->value();
        }
    }

    public function consumer(): ComponentIdentifier
    {
        return $this->consumer;
    }

    public function capability(): CapabilityIdentifier
    {
        return $this->capability;
    }

    /**
     * @return list<ComponentIdentifier>
     */
    public function providers(): array
    {
        return $this->providers;
    }
}
