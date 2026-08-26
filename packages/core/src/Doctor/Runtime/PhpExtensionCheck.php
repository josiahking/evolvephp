<?php

declare(strict_types=1);

namespace Evolve\Core\Doctor\Runtime;

use Closure;
use Evolve\Core\Doctor\DoctorCheck;
use Evolve\Core\Doctor\DoctorFinding;
use Evolve\Core\Doctor\DoctorStatus;
use InvalidArgumentException;

final readonly class PhpExtensionCheck implements DoctorCheck
{
    public const IDENTIFIER = 'runtime.php.extensions';

    /** @var list<string> */
    private array $requiredExtensions;

    /**
     * @param array<mixed> $requiredExtensions
     * @param (Closure(string): bool)|null $extensionLoaded
     */
    public function __construct(
        array $requiredExtensions,
        private ?Closure $extensionLoaded = null,
    ) {
        if (! array_is_list($requiredExtensions)) {
            throw new InvalidArgumentException('Required PHP extensions must be provided as a list.');
        }

        $seenExtensions = [];
        $normalizedExtensions = [];

        foreach ($requiredExtensions as $extension) {
            if (! is_string($extension) || $extension === '') {
                throw new InvalidArgumentException('Required PHP extension names must be non-empty strings.');
            }

            if (preg_match('/\A[A-Za-z0-9_.-]+\z/', $extension) !== 1) {
                throw new InvalidArgumentException(sprintf('Required PHP extension name "%s" is malformed.', $extension));
            }

            $canonicalExtension = strtolower($extension);

            if (isset($seenExtensions[$canonicalExtension])) {
                throw new InvalidArgumentException(sprintf('Duplicate required PHP extension "%s" declared.', $extension));
            }

            $seenExtensions[$canonicalExtension] = true;
            $normalizedExtensions[] = $extension;
        }

        $this->requiredExtensions = $normalizedExtensions;
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(): DoctorFinding
    {
        $missingExtensions = [];
        $extensionLoaded = $this->extensionLoaded
            ?? static fn(string $extension): bool => extension_loaded($extension);

        foreach ($this->requiredExtensions as $extension) {
            if (! $extensionLoaded($extension)) {
                $missingExtensions[] = $extension;
            }
        }

        if ($missingExtensions !== []) {
            $missing = implode(', ', $missingExtensions);

            return new DoctorFinding(
                self::IDENTIFIER,
                DoctorStatus::Fail,
                sprintf('Missing required PHP extension%s: %s.', count($missingExtensions) === 1 ? '' : 's', $missing),
                sprintf('Install or enable the missing PHP extension%s: %s.', count($missingExtensions) === 1 ? '' : 's', $missing),
            );
        }

        if ($this->requiredExtensions === []) {
            return new DoctorFinding(
                self::IDENTIFIER,
                DoctorStatus::Pass,
                'No PHP extensions were required for this diagnostic check.',
            );
        }

        return new DoctorFinding(
            self::IDENTIFIER,
            DoctorStatus::Pass,
            sprintf('All required PHP extensions are loaded: %s.', implode(', ', $this->requiredExtensions)),
        );
    }
}
