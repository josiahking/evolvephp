<?php

declare(strict_types=1);

namespace Evolve\Bridge\Contracts;

use InvalidArgumentException;

/**
 * @experimental
 */
final readonly class BridgeContext
{
    public function __construct(
        private string $requestIdentifier,
        private string $correlationIdentifier,
        private ?string $principalIdentifier = null,
        private ?string $tenantIdentifier = null,
        private ?string $locale = null,
        private ?string $timezone = null,
    ) {
        $this->assertNonBlank($requestIdentifier, 'requestIdentifier');
        $this->assertNonBlank($correlationIdentifier, 'correlationIdentifier');
        $this->assertOptionalNonBlank($principalIdentifier, 'principalIdentifier');
        $this->assertOptionalNonBlank($tenantIdentifier, 'tenantIdentifier');
        $this->assertOptionalNonBlank($locale, 'locale');
        $this->assertOptionalNonBlank($timezone, 'timezone');
    }

    public function requestIdentifier(): string
    {
        return $this->requestIdentifier;
    }

    public function correlationIdentifier(): string
    {
        return $this->correlationIdentifier;
    }

    public function principalIdentifier(): ?string
    {
        return $this->principalIdentifier;
    }

    public function tenantIdentifier(): ?string
    {
        return $this->tenantIdentifier;
    }

    public function locale(): ?string
    {
        return $this->locale;
    }

    public function timezone(): ?string
    {
        return $this->timezone;
    }

    private function assertOptionalNonBlank(?string $value, string $field): void
    {
        if ($value === null) {
            return;
        }

        $this->assertNonBlank($value, $field);
    }

    private function assertNonBlank(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException($field . ' must be nonblank.');
        }
    }
}
