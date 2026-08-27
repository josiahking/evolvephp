<?php

declare(strict_types=1);

namespace Evolve\Core\Doctor\Project;

use Closure;
use Evolve\Core\Doctor\DoctorCheck;
use Evolve\Core\Doctor\DoctorFinding;
use Evolve\Core\Doctor\DoctorStatus;
use InvalidArgumentException;
use JsonException;
use stdClass;

final readonly class ComposerRequiredExtensionsCheck implements DoctorCheck
{
    public const IDENTIFIER = 'project.composer.extensions';

    /**
     * @param (Closure(string): bool)|null $extensionLoaded
     */
    public function __construct(
        private string $composerJsonPath,
        private ?Closure $extensionLoaded = null,
    ) {
        if (trim($composerJsonPath) === '') {
            throw new InvalidArgumentException('Composer project manifest path must not be empty.');
        }

        if (str_contains($composerJsonPath, '://')) {
            throw new InvalidArgumentException('Composer project manifest path must be a local filesystem path.');
        }
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(): DoctorFinding
    {
        if (! is_file($this->composerJsonPath) || ! is_readable($this->composerJsonPath)) {
            return $this->manifestUnavailableFinding();
        }

        $contents = @file_get_contents($this->composerJsonPath);

        if ($contents === false) {
            return $this->manifestUnavailableFinding();
        }

        try {
            $manifest = json_decode($contents, false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new DoctorFinding(
                self::IDENTIFIER,
                DoctorStatus::Fail,
                sprintf('Composer project manifest is not valid JSON at %s.', $this->composerJsonPath),
                'Fix the JSON syntax in the project composer.json manifest.',
            );
        }

        if (! $manifest instanceof stdClass) {
            return new DoctorFinding(
                self::IDENTIFIER,
                DoctorStatus::Fail,
                sprintf('Composer project manifest must contain a JSON object at %s.', $this->composerJsonPath),
                'Ensure the project composer.json manifest root is a JSON object.',
            );
        }

        if (! property_exists($manifest, 'require')) {
            return $this->noRequiredExtensionsFinding();
        }

        if (! $manifest->require instanceof stdClass) {
            return new DoctorFinding(
                self::IDENTIFIER,
                DoctorStatus::Fail,
                sprintf('Composer project runtime requirements must be a JSON object at %s.', $this->composerJsonPath),
                'Ensure the project composer.json require section is a JSON object.',
            );
        }

        $requiredExtensions = [];
        $seenExtensions = [];

        $runtimeRequirements = get_object_vars($manifest->require);

        foreach ($runtimeRequirements as $packageName => $constraint) {
            if (! str_starts_with(strtolower($packageName), 'ext-')) {
                continue;
            }

            $extensionName = substr($packageName, 4);

            if ($extensionName === '' || preg_match('/\A[A-Za-z0-9_.-]+\z/', $extensionName) !== 1) {
                return new DoctorFinding(
                    self::IDENTIFIER,
                    DoctorStatus::Fail,
                    sprintf('Composer project PHP extension requirement "%s" is malformed at %s.', $packageName, $this->composerJsonPath),
                    'Declare PHP extension requirements as ext-<name> using letters, digits, underscore, dot, or dash.',
                );
            }

            if (! is_string($constraint) || trim($constraint) === '') {
                return new DoctorFinding(
                    self::IDENTIFIER,
                    DoctorStatus::Fail,
                    sprintf('Composer project PHP extension requirement "%s" must use a non-empty string constraint at %s.', $packageName, $this->composerJsonPath),
                    'Declare Composer PHP extension constraints as non-empty strings.',
                );
            }

            $canonicalExtensionName = strtolower($extensionName);

            if (isset($seenExtensions[$canonicalExtensionName])) {
                return new DoctorFinding(
                    self::IDENTIFIER,
                    DoctorStatus::Fail,
                    sprintf('Composer project PHP extension "%s" is declared more than once after normalization at %s.', $canonicalExtensionName, $this->composerJsonPath),
                    'Remove duplicate Composer PHP extension requirements after case normalization.',
                );
            }

            $seenExtensions[$canonicalExtensionName] = true;
            $requiredExtensions[] = $canonicalExtensionName;
        }

        if ($requiredExtensions === []) {
            return $this->noRequiredExtensionsFinding();
        }

        sort($requiredExtensions);

        $missingExtensions = [];
        $extensionLoaded = $this->extensionLoaded
            ?? static fn(string $extension): bool => extension_loaded($extension);

        foreach ($requiredExtensions as $extension) {
            if (! $extensionLoaded($extension)) {
                $missingExtensions[] = $extension;
            }
        }

        if ($missingExtensions !== []) {
            $missing = implode(', ', $missingExtensions);

            return new DoctorFinding(
                self::IDENTIFIER,
                DoctorStatus::Fail,
                sprintf(
                    'Missing Composer-declared PHP extension%s: %s.',
                    count($missingExtensions) === 1 ? '' : 's',
                    $missing,
                ),
                sprintf(
                    'Install or enable the missing PHP extension%s: %s.',
                    count($missingExtensions) === 1 ? '' : 's',
                    $missing,
                ),
            );
        }

        return new DoctorFinding(
            self::IDENTIFIER,
            DoctorStatus::Pass,
            sprintf('All Composer-declared PHP extensions are loaded: %s.', implode(', ', $requiredExtensions)),
        );
    }

    private function manifestUnavailableFinding(): DoctorFinding
    {
        return new DoctorFinding(
            self::IDENTIFIER,
            DoctorStatus::Fail,
            sprintf('Composer project manifest is unavailable at %s.', $this->composerJsonPath),
            'Create a readable composer.json in the current project directory.',
        );
    }

    private function noRequiredExtensionsFinding(): DoctorFinding
    {
        return new DoctorFinding(
            self::IDENTIFIER,
            DoctorStatus::Pass,
            'Composer project declares no required PHP extensions.',
        );
    }
}
