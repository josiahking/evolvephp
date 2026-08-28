<?php

declare(strict_types=1);

namespace Evolve\Core\Doctor\Project;

use Closure;
use Evolve\Core\Doctor\DoctorCheck;
use Evolve\Core\Doctor\DoctorFinding;
use Evolve\Core\Doctor\DoctorStatus;
use InvalidArgumentException;

final readonly class EnvironmentVariablesCheck implements DoctorCheck
{
    public const IDENTIFIER = 'project.environment.variables';

    /**
     * @var list<string>
     */
    private array $requiredVariables;

    /**
     * @param array<mixed> $requiredVariables
     * @param (Closure(string): (string|false))|null $environmentLookup
     */
    public function __construct(
        array $requiredVariables,
        private ?Closure $environmentLookup = null,
    ) {
        $this->requiredVariables = self::validateRequiredVariables($requiredVariables);
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(): DoctorFinding
    {
        if ($this->requiredVariables === []) {
            return new DoctorFinding(
                self::IDENTIFIER,
                DoctorStatus::Pass,
                'No environment variables were required for this diagnostic check.',
            );
        }

        $lookup = $this->environmentLookup ?? static fn(string $name): string|false => getenv($name);
        $missingVariables = [];

        foreach ($this->requiredVariables as $variableName) {
            if ($lookup($variableName) === false) {
                $missingVariables[] = $variableName;
            }
        }

        if ($missingVariables === []) {
            return new DoctorFinding(
                self::IDENTIFIER,
                DoctorStatus::Pass,
                sprintf(
                    'All required environment variables are present: %s.',
                    implode(', ', $this->requiredVariables),
                ),
            );
        }

        $missingVariableList = implode(', ', $missingVariables);

        if (count($missingVariables) === 1) {
            return new DoctorFinding(
                self::IDENTIFIER,
                DoctorStatus::Fail,
                sprintf('Missing required environment variable: %s.', $missingVariableList),
                sprintf('Define the missing environment variable before running the application: %s.', $missingVariableList),
            );
        }

        return new DoctorFinding(
            self::IDENTIFIER,
            DoctorStatus::Fail,
            sprintf('Missing required environment variables: %s.', $missingVariableList),
            sprintf('Define the missing environment variables before running the application: %s.', $missingVariableList),
        );
    }

    /**
     * @param array<mixed> $requiredVariables
     * @return list<string>
     */
    private static function validateRequiredVariables(array $requiredVariables): array
    {
        if (! array_is_list($requiredVariables)) {
            throw new InvalidArgumentException('Required environment variables must be provided as a list.');
        }

        $seenVariableNames = [];
        $validatedVariables = [];

        foreach ($requiredVariables as $variableName) {
            if (! is_string($variableName)) {
                throw new InvalidArgumentException('Required environment variable names must be strings.');
            }

            if ($variableName === '') {
                throw new InvalidArgumentException('Required environment variable names must be non-empty strings.');
            }

            if (str_contains($variableName, '=')) {
                throw new InvalidArgumentException('Required environment variable names must not contain equals signs.');
            }

            if (str_contains($variableName, "\0")) {
                throw new InvalidArgumentException('Required environment variable names must not contain ASCII NUL bytes.');
            }

            if (preg_match('/[\s\x00-\x1F\x7F]/', $variableName) === 1) {
                throw new InvalidArgumentException('Required environment variable names must not contain whitespace or control characters.');
            }

            if (isset($seenVariableNames[$variableName])) {
                throw new InvalidArgumentException('Required environment variable names must not contain exact duplicates.');
            }

            $seenVariableNames[$variableName] = true;
            $validatedVariables[] = $variableName;
        }

        return $validatedVariables;
    }
}
