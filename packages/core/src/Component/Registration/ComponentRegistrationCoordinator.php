<?php

declare(strict_types=1);

namespace Evolve\Core\Component\Registration;

use Evolve\Contracts\Component\ComponentGraphDeclaration;
use Evolve\Core\Component\ResolvedComponentGraph;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ComponentServiceRegistrationFailed;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * @internal Core-owned component service-definition registration coordinator.
 */
final class ComponentRegistrationCoordinator
{
    private const STATE_PENDING = 'pending';
    private const STATE_RUNNING = 'running';
    private const STATE_FINISHED = 'finished';
    private const STATE_FAILED = 'failed';
    private const COMPONENT_KEY_PREFIX = 'component:';

    private string $state = self::STATE_PENDING;

    /**
     * @param array<mixed> $registrations
     */
    public function __construct(
        private ResolvedComponentGraph $graph,
        private ServiceRegistry $registry,
        private array $registrations,
    ) {}

    public function register(): void
    {
        if ($this->state !== self::STATE_PENDING) {
            throw new LogicException('Component registration coordinator has already run.');
        }

        $this->state = self::STATE_RUNNING;

        try {
            $this->registry->assertMutable();
            $registrations = $this->registrationsByResolvedDeclaration();
            $buffer = new ServiceDefinitionContributionBuffer($this->registry->definitionIdentifiers());

            foreach ($this->graph->orderedDeclarations() as $declaration) {
                $registrar = new RestrictedServiceDefinitionRegistrar($buffer);

                try {
                    $registrations[spl_object_id($declaration)]->contribute($registrar);
                } catch (Throwable $exception) {
                    throw ComponentServiceRegistrationFailed::forComponent($declaration->identifier(), $exception);
                } finally {
                    $registrar->close();
                }
            }

            $definitions = $buffer->definitions();
            $this->registry->assertCanPublishDefinitions($definitions);
            $this->registry->publishDefinitions($definitions);

            $this->state = self::STATE_FINISHED;
        } catch (Throwable $exception) {
            if ($this->state !== self::STATE_FINISHED) {
                $this->state = self::STATE_FAILED;
            }

            throw $exception;
        }
    }

    /**
     * @return array<int, ComponentRegistration>
     */
    private function registrationsByResolvedDeclaration(): array
    {
        $expectedObjects = [];
        $expectedIdentifiers = [];

        foreach ($this->graph->orderedDeclarations() as $declaration) {
            $expectedObjects[spl_object_id($declaration)] = true;
            $expectedIdentifiers[$this->componentKey($declaration)] = spl_object_id($declaration);
        }

        $registrations = [];

        foreach ($this->registrations as $registration) {
            if (!$registration instanceof ComponentRegistration) {
                throw new InvalidArgumentException('Component registrations must contain ComponentRegistration instances.');
            }

            $declaration = $registration->declaration();
            $objectId = spl_object_id($declaration);
            $componentKey = $this->componentKey($declaration);

            if (!isset($expectedIdentifiers[$componentKey])) {
                throw new InvalidArgumentException('Component registration contains an extra declaration binding.');
            }

            if ($expectedIdentifiers[$componentKey] !== $objectId) {
                throw new InvalidArgumentException('Component registration must bind the exact resolved declaration object.');
            }

            if (!isset($expectedObjects[$objectId])) {
                throw new InvalidArgumentException('Component registration contains an extra declaration binding.');
            }

            if (isset($registrations[$objectId])) {
                throw new InvalidArgumentException('Component registration contains a duplicate declaration binding.');
            }

            $registrations[$objectId] = $registration;
        }

        if (count($registrations) !== count($expectedObjects)) {
            throw new InvalidArgumentException('Component registration is missing a declaration binding.');
        }

        return $registrations;
    }

    private function componentKey(ComponentGraphDeclaration $declaration): string
    {
        return self::COMPONENT_KEY_PREFIX . $declaration->identifier()->value();
    }
}
