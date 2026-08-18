<?php

declare(strict_types=1);

namespace Evolve\Core\Component;

use Evolve\Contracts\Component\CapabilityIdentifier;
use Evolve\Contracts\Component\ComponentGraphDeclaration;
use Evolve\Contracts\Component\ComponentIdentifier;
use InvalidArgumentException;

/**
 * Immutable dependency-first component graph resolution result.
 *
 * @experimental
 */
final readonly class ResolvedComponentGraph
{
    private const COMPONENT_KEY_PREFIX = 'component:';
    private const CAPABILITY_KEY_PREFIX = 'capability:';

    /**
     * @var list<ComponentGraphDeclaration>
     */
    private array $orderedDeclarations;

    /**
     * @var array<string, array<string, list<ComponentGraphDeclaration>>>
     */
    private array $resolvedProviders;

    /**
     * @internal Resolver-owned construction; external callers should use ComponentGraphResolver.
     *
     * @param list<ComponentGraphDeclaration> $orderedDeclarations
     * @param array<string, array<string, list<ComponentGraphDeclaration>>> $resolvedProviders
     */
    public function __construct(array $orderedDeclarations, array $resolvedProviders = [])
    {
        self::assertOrderedDeclarations($orderedDeclarations);
        self::assertResolvedProviders($resolvedProviders);

        $this->orderedDeclarations = $orderedDeclarations;
        $this->resolvedProviders = $resolvedProviders;
    }

    /**
     * @param array<mixed> $orderedDeclarations
     */
    private static function assertOrderedDeclarations(array $orderedDeclarations): void
    {
        if (!array_is_list($orderedDeclarations)) {
            throw new InvalidArgumentException('Ordered declarations must be a list.');
        }

        foreach ($orderedDeclarations as $declaration) {
            if (!$declaration instanceof ComponentGraphDeclaration) {
                throw new InvalidArgumentException('Ordered declarations must contain component graph declarations.');
            }
        }
    }

    /**
     * @param array<mixed> $resolvedProviders
     */
    private static function assertResolvedProviders(array $resolvedProviders): void
    {
        foreach ($resolvedProviders as $consumer => $capabilities) {
            if (!is_string($consumer) || !is_array($capabilities)) {
                throw new InvalidArgumentException('Resolved providers must be keyed by consumer identifier.');
            }

            foreach ($capabilities as $capability => $providers) {
                if (!is_string($capability) || !array_is_list($providers)) {
                    throw new InvalidArgumentException('Resolved provider capabilities must contain provider lists.');
                }

                foreach ($providers as $provider) {
                    if (!$provider instanceof ComponentGraphDeclaration) {
                        throw new InvalidArgumentException('Resolved provider entries must be component graph declarations.');
                    }
                }
            }
        }
    }

    /**
     * @return list<ComponentGraphDeclaration>
     */
    public function orderedDeclarations(): array
    {
        return $this->orderedDeclarations;
    }

    /**
     * @return list<ComponentGraphDeclaration>
     */
    public function resolvedProvidersFor(
        ComponentIdentifier $consumer,
        CapabilityIdentifier $capability,
    ): array {
        return $this->resolvedProviders[$this->componentKey($consumer->value())][$this->capabilityKey($capability->value())] ?? [];
    }

    private function componentKey(string $identifier): string
    {
        return self::COMPONENT_KEY_PREFIX . $identifier;
    }

    private function capabilityKey(string $capability): string
    {
        return self::CAPABILITY_KEY_PREFIX . $capability;
    }
}
