<?php

declare(strict_types=1);

namespace Evolve\Core\Doctor;

use InvalidArgumentException;
use LogicException;

final readonly class DoctorRunner
{
    /** @var list<array{identifier: string, check: DoctorCheck}> */
    private array $checks;

    /**
     * @param iterable<mixed> $checks
     */
    public function __construct(iterable $checks)
    {
        $normalizedChecks = [];
        $seenIdentifiers = [];

        foreach ($checks as $check) {
            if (! $check instanceof DoctorCheck) {
                throw new InvalidArgumentException('Doctor runner checks must implement DoctorCheck.');
            }

            $identifier = $check->identifier();
            DoctorFinding::assertValidIdentifier($identifier);

            if (isset($seenIdentifiers[$identifier])) {
                throw new InvalidArgumentException(sprintf(
                    'Duplicate doctor check identifier "%s" registered.',
                    $identifier,
                ));
            }

            $seenIdentifiers[$identifier] = true;
            $normalizedChecks[] = [
                'identifier' => $identifier,
                'check' => $check,
            ];
        }

        $this->checks = $normalizedChecks;
    }

    public function run(): DoctorReport
    {
        $findings = [];

        foreach ($this->checks as $registeredCheck) {
            $identifier = $registeredCheck['identifier'];
            $check = $registeredCheck['check'];
            $finding = $check->run();

            if ($finding->identifier() !== $identifier) {
                throw new LogicException(sprintf(
                    'Doctor check "%s" returned finding "%s".',
                    $identifier,
                    $finding->identifier(),
                ));
            }

            $findings[] = $finding;
        }

        return new DoctorReport($findings);
    }
}
