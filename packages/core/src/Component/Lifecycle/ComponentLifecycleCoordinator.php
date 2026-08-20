<?php

declare(strict_types=1);

namespace Evolve\Core\Component\Lifecycle;

use Evolve\Contracts\Component\ComponentGraphDeclaration;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Core\Component\Registration\ComponentRegistration;
use Evolve\Core\Component\Registration\ComponentRegistrationCoordinator;
use Evolve\Core\Component\ResolvedComponentGraph;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Exception\ComponentShutdownFailed;
use Evolve\Core\Exception\ComponentStartupFailed;
use Evolve\Core\Exception\InvalidLifecycleTransition;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * @internal Core-owned component registration, boot, ready and shutdown coordinator.
 */
final class ComponentLifecycleCoordinator
{
    private const STATE_PENDING = 'pending';
    private const STATE_REGISTERING = 'registering';
    private const STATE_REGISTERED = 'registered';
    private const STATE_BOOTING = 'booting';
    private const STATE_BOOTED = 'booted';
    private const STATE_READYING = 'readying';
    private const STATE_READY = 'ready';
    private const STATE_SHUTTING_DOWN = 'shutting-down';
    private const STATE_STOPPED = 'stopped';
    private const STATE_FAILED = 'failed';
    private const COMPONENT_KEY_PREFIX = 'component:';

    private string $state = self::STATE_PENDING;

    /**
     * @var list<ComponentLifecycleBinding>
     */
    private array $orderedBindings;

    /**
     * @var list<ComponentLifecycleBinding>
     */
    private array $bootedBindings = [];

    /**
     * @param iterable<mixed> $bindings
     */
    public function __construct(
        private ResolvedComponentGraph $graph,
        iterable $bindings,
    ) {
        $this->orderedBindings = $this->orderedBindings($bindings);
    }

    public function register(ServiceRegistry $registry): void
    {
        $this->assertState(self::STATE_PENDING, 'Component lifecycle registration cannot run from the current lifecycle state.');
        $this->state = self::STATE_REGISTERING;

        try {
            $registrations = array_map(
                static fn(ComponentLifecycleBinding $binding): ComponentRegistration => new ComponentRegistration(
                    $binding->declaration(),
                    static function (ServiceDefinitionRegistrar $registrar) use ($binding): void {
                        $binding->entryPoint()->register($registrar);
                    },
                ),
                $this->orderedBindings,
            );

            (new ComponentRegistrationCoordinator($this->graph, $registry, $registrations))->register();

            $this->state = self::STATE_REGISTERED;
        } catch (Throwable $exception) {
            $this->state = self::STATE_FAILED;

            throw $exception;
        }
    }

    public function boot(ContainerInterface $services): void
    {
        $this->assertState(self::STATE_REGISTERED, 'Component lifecycle boot cannot run from the current lifecycle state.');
        $this->state = self::STATE_BOOTING;

        foreach ($this->orderedBindings as $binding) {
            $context = new RestrictedComponentBootContext($services);

            try {
                $binding->entryPoint()->boot($context);
                $context->closeAfterSuccessfulBoot();
                $this->bootedBindings[] = $binding;
            } catch (Throwable $exception) {
                $cleanupFailures = $this->attributedFailures($binding->declaration(), $context->closeAfterFailedBoot());
                $cleanupFailures = array_merge($cleanupFailures, $this->shutdownBootedReverse());
                $this->state = self::STATE_FAILED;

                throw ComponentStartupFailed::duringBoot($binding->declaration()->identifier(), $exception, $cleanupFailures);
            }
        }

        $this->state = self::STATE_BOOTED;
    }

    public function ready(): void
    {
        $this->assertState(self::STATE_BOOTED, 'Component lifecycle ready cannot run from the current lifecycle state.');
        $this->state = self::STATE_READYING;

        foreach ($this->orderedBindings as $binding) {
            try {
                $binding->entryPoint()->ready();
            } catch (Throwable $exception) {
                $cleanupFailures = $this->shutdownBootedReverse();
                $this->state = self::STATE_FAILED;

                throw ComponentStartupFailed::duringReady($binding->declaration()->identifier(), $exception, $cleanupFailures);
            }
        }

        $this->state = self::STATE_READY;
    }

    public function shutdown(): void
    {
        $this->assertState(self::STATE_READY, 'Component lifecycle shutdown cannot run from the current lifecycle state.');
        $this->state = self::STATE_SHUTTING_DOWN;

        $failures = $this->shutdownBootedReverse();
        $this->state = self::STATE_STOPPED;

        if ($failures !== []) {
            throw new ComponentShutdownFailed($failures);
        }
    }

    /**
     * @param iterable<mixed> $bindings
     * @return list<ComponentLifecycleBinding>
     */
    private function orderedBindings(iterable $bindings): array
    {
        $expectedObjects = [];
        $expectedIdentifiers = [];

        foreach ($this->graph->orderedDeclarations() as $declaration) {
            $expectedObjects[spl_object_id($declaration)] = $declaration;
            $expectedIdentifiers[$this->componentKey($declaration)] = spl_object_id($declaration);
        }

        $bindingsByObject = [];
        $entryPointsByObject = [];

        foreach ($bindings as $binding) {
            if (! $binding instanceof ComponentLifecycleBinding) {
                throw new InvalidArgumentException('Component lifecycle bindings must contain ComponentLifecycleBinding instances.');
            }

            $declaration = $binding->declaration();
            $objectId = spl_object_id($declaration);
            $entryPointObjectId = spl_object_id($binding->entryPoint());
            $componentKey = $this->componentKey($declaration);

            if (! isset($expectedIdentifiers[$componentKey])) {
                throw new InvalidArgumentException('Component lifecycle binding contains an extra declaration binding.');
            }

            if ($expectedIdentifiers[$componentKey] !== $objectId) {
                throw new InvalidArgumentException('Component lifecycle binding must bind the exact resolved declaration object.');
            }

            if (! isset($expectedObjects[$objectId])) {
                throw new InvalidArgumentException('Component lifecycle binding contains an extra declaration binding.');
            }

            if (isset($bindingsByObject[$objectId])) {
                throw new InvalidArgumentException('Component lifecycle binding contains a duplicate declaration binding.');
            }

            if (isset($entryPointsByObject[$entryPointObjectId])) {
                throw new InvalidArgumentException('Component lifecycle entry-point objects must be unique per resolved component binding.');
            }

            $bindingsByObject[$objectId] = $binding;
            $entryPointsByObject[$entryPointObjectId] = true;
        }

        if (count($bindingsByObject) !== count($expectedObjects)) {
            throw new InvalidArgumentException('Component lifecycle binding is missing a declaration binding.');
        }

        $ordered = [];

        foreach ($this->graph->orderedDeclarations() as $declaration) {
            $ordered[] = $bindingsByObject[spl_object_id($declaration)];
        }

        return $ordered;
    }

    /**
     * @return list<ComponentLifecycleFailure>
     */
    private function shutdownBootedReverse(): array
    {
        $failures = [];

        for ($index = count($this->bootedBindings) - 1; $index >= 0; --$index) {
            $binding = $this->bootedBindings[$index];

            try {
                $binding->entryPoint()->shutdown();
            } catch (Throwable $exception) {
                $failures[] = new ComponentLifecycleFailure($binding->declaration()->identifier(), $exception);
            }
        }

        $this->bootedBindings = [];

        return $failures;
    }

    /**
     * @param list<Throwable> $throwables
     * @return list<ComponentLifecycleFailure>
     */
    private function attributedFailures(ComponentGraphDeclaration $declaration, array $throwables): array
    {
        return array_map(
            static fn(Throwable $throwable): ComponentLifecycleFailure => new ComponentLifecycleFailure($declaration->identifier(), $throwable),
            $throwables,
        );
    }

    private function assertState(string $expected, string $message): void
    {
        if ($this->state !== $expected) {
            throw new InvalidLifecycleTransition($message);
        }
    }

    private function componentKey(ComponentGraphDeclaration $declaration): string
    {
        return self::COMPONENT_KEY_PREFIX . $declaration->identifier()->value();
    }
}
