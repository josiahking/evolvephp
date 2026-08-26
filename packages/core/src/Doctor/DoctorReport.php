<?php

declare(strict_types=1);

namespace Evolve\Core\Doctor;

use InvalidArgumentException;

final readonly class DoctorReport
{
    /** @var list<DoctorFinding> */
    private array $findings;

    /**
     * @param iterable<mixed> $findings
     */
    public function __construct(iterable $findings = [])
    {
        $normalizedFindings = [];

        foreach ($findings as $finding) {
            if (! $finding instanceof DoctorFinding) {
                throw new InvalidArgumentException('Doctor report findings must contain only DoctorFinding instances.');
            }

            $normalizedFindings[] = $finding;
        }

        $this->findings = $normalizedFindings;
    }

    /**
     * @return list<DoctorFinding>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    public function successful(): bool
    {
        return ! $this->hasFailures();
    }

    public function hasWarnings(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->status() === DoctorStatus::Warning) {
                return true;
            }
        }

        return false;
    }

    public function hasFailures(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->status() === DoctorStatus::Fail) {
                return true;
            }
        }

        return false;
    }
}
