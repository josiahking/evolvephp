<?php

declare(strict_types=1);

namespace Evolve\Core\Doctor\Runtime;

use Evolve\Core\Doctor\DoctorCheck;
use Evolve\Core\Doctor\DoctorFinding;
use Evolve\Core\Doctor\DoctorStatus;
use InvalidArgumentException;

final readonly class PhpVersionCheck implements DoctorCheck
{
    public const IDENTIFIER = 'runtime.php.version';

    public function __construct(
        private string $currentVersion = PHP_VERSION,
        private string $minimumVersion = '8.4.0',
    ) {
        $this->assertValidVersion($currentVersion, 'current');
        $this->assertValidVersion($minimumVersion, 'minimum');
    }

    public function identifier(): string
    {
        return self::IDENTIFIER;
    }

    public function run(): DoctorFinding
    {
        if (version_compare($this->currentVersion, $this->minimumVersion, '<')) {
            return new DoctorFinding(
                self::IDENTIFIER,
                DoctorStatus::Fail,
                sprintf(
                    'PHP %s is below the minimum supported PHP version %s.',
                    $this->currentVersion,
                    $this->minimumVersion,
                ),
                sprintf('Upgrade PHP to version %s or higher.', $this->minimumVersion),
            );
        }

        return new DoctorFinding(
            self::IDENTIFIER,
            DoctorStatus::Pass,
            sprintf(
                'PHP %s satisfies the minimum supported PHP version %s.',
                $this->currentVersion,
                $this->minimumVersion,
            ),
        );
    }

    private function assertValidVersion(string $version, string $label): void
    {
        if (preg_match('/\A\d+\.\d+\.\d+(?:[A-Za-z0-9._+-]+)?\z/', $version) !== 1) {
            throw new InvalidArgumentException(sprintf('The %s PHP version "%s" is malformed.', $label, $version));
        }
    }
}
