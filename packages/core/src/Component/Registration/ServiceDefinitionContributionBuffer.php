<?php

declare(strict_types=1);

namespace Evolve\Core\Component\Registration;

use Evolve\Core\Container\ServiceDefinition;
use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Exception\InvalidServiceDefinition;

/**
 * @internal Stages component-contributed service definitions before atomic publication.
 */
final class ServiceDefinitionContributionBuffer
{
    private const SERVICE_KEY_PREFIX = 'service:';

    /**
     * @var array<string, true>
     */
    private array $reserved = [];

    /**
     * @var array<string, ServiceDefinition>
     */
    private array $definitions = [];

    /**
     * @param list<string> $reservedIdentifiers
     */
    public function __construct(array $reservedIdentifiers)
    {
        foreach ($reservedIdentifiers as $id) {
            $this->reserved[$this->serviceKey($id)] = true;
        }
    }

    public function stage(string $id, ServiceLifetime $lifetime, callable $factory): void
    {
        if ($id === '') {
            throw new InvalidServiceDefinition('Service identifier must be a non-empty string.');
        }

        $key = $this->serviceKey($id);

        if (isset($this->reserved[$key]) || isset($this->definitions[$key])) {
            throw new InvalidServiceDefinition('Service identifier is already registered.');
        }

        $this->definitions[$key] = new ServiceDefinition($id, $lifetime, $factory);
    }

    /**
     * @return list<ServiceDefinition>
     */
    public function definitions(): array
    {
        return array_values($this->definitions);
    }

    private function serviceKey(string $id): string
    {
        return self::SERVICE_KEY_PREFIX . $id;
    }
}
