<?php

declare(strict_types=1);

namespace Evolve\Core\Doctor\Project;

use Closure;
use Evolve\Core\Doctor\DoctorCheck;
use Evolve\Core\Doctor\DoctorFinding;
use Evolve\Core\Doctor\DoctorStatus;
use InvalidArgumentException;

final readonly class WritablePathsCheck implements DoctorCheck
{
    public const IDENTIFIER = 'project.paths.writable';

    /**
     * @var list<string>
     */
    private array $requiredPaths;

    /**
     * @param array<mixed> $requiredPaths
     * @param (Closure(string): bool)|null $isWritable
     */
    public function __construct(
        array $requiredPaths,
        private ?Closure $isWritable = null,
    ) {
        $this->requiredPaths = self::validateRequiredPaths($requiredPaths);
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(): DoctorFinding
    {
        if ($this->requiredPaths === []) {
            return new DoctorFinding(
                self::IDENTIFIER,
                DoctorStatus::Pass,
                'No writable paths were required for this diagnostic check.',
            );
        }

        $isWritable = $this->isWritable ?? static fn(string $path): bool => is_writable($path);
        $nonWritablePaths = [];

        foreach ($this->requiredPaths as $path) {
            if (! $isWritable($path)) {
                $nonWritablePaths[] = $path;
            }
        }

        if ($nonWritablePaths === []) {
            return new DoctorFinding(
                self::IDENTIFIER,
                DoctorStatus::Pass,
                sprintf('All required paths are writable: %s.', implode(', ', $this->requiredPaths)),
            );
        }

        $nonWritablePathList = implode(', ', $nonWritablePaths);

        if (count($nonWritablePaths) === 1) {
            return new DoctorFinding(
                self::IDENTIFIER,
                DoctorStatus::Fail,
                sprintf('Required path is not writable: %s.', $nonWritablePathList),
                sprintf('Ensure the required path is writable by the PHP process: %s.', $nonWritablePathList),
            );
        }

        return new DoctorFinding(
            self::IDENTIFIER,
            DoctorStatus::Fail,
            sprintf('Required paths are not writable: %s.', $nonWritablePathList),
            sprintf('Ensure the required paths are writable by the PHP process: %s.', $nonWritablePathList),
        );
    }

    /**
     * @param array<mixed> $requiredPaths
     * @return list<string>
     */
    private static function validateRequiredPaths(array $requiredPaths): array
    {
        if (! array_is_list($requiredPaths)) {
            throw new InvalidArgumentException('Required writable paths must be provided as a list.');
        }

        $seenPaths = [];
        $validatedPaths = [];

        foreach ($requiredPaths as $path) {
            if (! is_string($path)) {
                throw new InvalidArgumentException('Required writable paths must be strings.');
            }

            if ($path === '') {
                throw new InvalidArgumentException('Required writable paths must be non-empty strings.');
            }

            if (preg_match('/\S/', $path) !== 1) {
                throw new InvalidArgumentException('Required writable paths must contain at least one non-whitespace character.');
            }

            if (str_contains($path, "\0")) {
                throw new InvalidArgumentException('Required writable paths must not contain ASCII NUL bytes.');
            }

            if (str_contains($path, '://')) {
                throw new InvalidArgumentException('Required writable paths must be local filesystem paths, not URIs or stream wrappers.');
            }

            if (isset($seenPaths[$path])) {
                throw new InvalidArgumentException('Required writable paths must not contain exact duplicates.');
            }

            $seenPaths[$path] = true;
            $validatedPaths[] = $path;
        }

        return $validatedPaths;
    }
}
