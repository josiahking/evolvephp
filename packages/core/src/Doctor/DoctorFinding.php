<?php

declare(strict_types=1);

namespace Evolve\Core\Doctor;

use InvalidArgumentException;

final readonly class DoctorFinding
{
    public function __construct(
        private string $identifier,
        private DoctorStatus $status,
        private string $message,
        private ?string $remediation = null,
    ) {
        self::assertValidIdentifier($identifier);

        if (trim($message) === '') {
            throw new InvalidArgumentException('Doctor finding message must not be empty.');
        }

        if ($remediation !== null && trim($remediation) === '') {
            throw new InvalidArgumentException('Doctor finding remediation must not be empty when provided.');
        }
    }

    public static function assertValidIdentifier(string $identifier): void
    {
        if ($identifier === '') {
            throw new InvalidArgumentException('Doctor finding identifier must not be empty.');
        }

        if (preg_match('/\A[a-z0-9_-]+(?:\.[a-z0-9_-]+)*\z/', $identifier) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Doctor finding identifier "%s" must use dot-separated lowercase ASCII segments.',
                $identifier,
            ));
        }
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function status(): DoctorStatus
    {
        return $this->status;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function remediation(): ?string
    {
        return $this->remediation;
    }
}
