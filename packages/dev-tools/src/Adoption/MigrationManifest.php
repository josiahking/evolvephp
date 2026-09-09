<?php

declare(strict_types=1);

namespace Evolve\DevTools\Adoption;

use InvalidArgumentException;

/**
 * @experimental
 */
final readonly class MigrationManifest
{
    /**
     * @var list<RouteOwnership>
     */
    private array $routeOwnership;

    /**
     * @var list<DataOwnership>
     */
    private array $dataOwnership;

    /**
     * @var list<string>
     */
    private array $compatibilityRequirements;

    /**
     * @var list<string>
     */
    private array $identitySecurityRequirements;

    /**
     * @param array<mixed> $routeOwnership
     * @param array<mixed> $dataOwnership
     * @param array<mixed> $compatibilityRequirements
     * @param array<mixed> $identitySecurityRequirements
     */
    public function __construct(
        private string $capability,
        private IntegrationMode $integrationMode,
        array $routeOwnership,
        array $dataOwnership,
        array $compatibilityRequirements,
        array $identitySecurityRequirements,
    ) {
        if (trim($capability) === '') {
            throw new InvalidArgumentException('Capability name must be non-empty.');
        }

        $this->routeOwnership = self::routeOwnershipList($routeOwnership);
        $this->dataOwnership = self::dataOwnershipList($dataOwnership);
        $this->compatibilityRequirements = self::nonEmptyStringList(
            $compatibilityRequirements,
            'Compatibility requirements',
        );
        $this->identitySecurityRequirements = self::nonEmptyStringList(
            $identitySecurityRequirements,
            'Identity and security requirements',
        );
    }

    public function capability(): string
    {
        return $this->capability;
    }

    public function integrationMode(): IntegrationMode
    {
        return $this->integrationMode;
    }

    /**
     * @return list<RouteOwnership>
     */
    public function routeOwnership(): array
    {
        return $this->routeOwnership;
    }

    /**
     * @return list<DataOwnership>
     */
    public function dataOwnership(): array
    {
        return $this->dataOwnership;
    }

    /**
     * @return list<string>
     */
    public function compatibilityRequirements(): array
    {
        return $this->compatibilityRequirements;
    }

    /**
     * @return list<string>
     */
    public function identitySecurityRequirements(): array
    {
        return $this->identitySecurityRequirements;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<RouteOwnership>
     */
    private static function routeOwnershipList(array $values): array
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('Route ownership must be a list.');
        }

        $routes = [];

        foreach ($values as $value) {
            if (! $value instanceof RouteOwnership) {
                throw new InvalidArgumentException('Route ownership may contain only route ownership declarations.');
            }

            if (isset($routes[$value->route()])) {
                throw new InvalidArgumentException('Route ownership contains duplicate route identifiers.');
            }

            $routes[$value->route()] = true;
        }

        return $values;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<DataOwnership>
     */
    private static function dataOwnershipList(array $values): array
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('Data ownership must be a list.');
        }

        $dataSets = [];

        foreach ($values as $value) {
            if (! $value instanceof DataOwnership) {
                throw new InvalidArgumentException('Data ownership may contain only data ownership declarations.');
            }

            if (isset($dataSets[$value->dataSet()])) {
                throw new InvalidArgumentException('Data ownership contains duplicate data set identifiers.');
            }

            $dataSets[$value->dataSet()] = true;
        }

        return $values;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function nonEmptyStringList(array $values, string $label): array
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException($label . ' must be a list.');
        }

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException($label . ' may contain only non-empty strings.');
            }
        }

        return $values;
    }
}
