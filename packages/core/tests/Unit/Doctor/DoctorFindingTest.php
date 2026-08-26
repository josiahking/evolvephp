<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Doctor;

use Evolve\Core\Doctor\DoctorFinding;
use Evolve\Core\Doctor\DoctorStatus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DoctorFindingTest extends TestCase
{
    public function testValidFindingPreservesValues(): void
    {
        $finding = new DoctorFinding(
            'runtime.php.version',
            DoctorStatus::Pass,
            'PHP version is supported.',
            'No remediation required.',
        );

        self::assertSame('runtime.php.version', $finding->identifier());
        self::assertSame(DoctorStatus::Pass, $finding->status());
        self::assertSame('PHP version is supported.', $finding->message());
        self::assertSame('No remediation required.', $finding->remediation());
    }

    public function testNullableRemediationIsPreserved(): void
    {
        $finding = new DoctorFinding(
            'runtime.php.extensions',
            DoctorStatus::Warning,
            'No extension requirements were declared.',
        );

        self::assertNull($finding->remediation());
    }

    public function testEmptyIdentifierIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DoctorFinding('', DoctorStatus::Pass, 'PHP version is supported.');
    }

    public function testMalformedIdentifierIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DoctorFinding('runtime php.version', DoctorStatus::Pass, 'PHP version is supported.');
    }

    public function testEmptyMessageIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DoctorFinding('runtime.php.version', DoctorStatus::Pass, '');
    }

    public function testEmptyProvidedRemediationIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DoctorFinding('runtime.php.version', DoctorStatus::Fail, 'PHP version is unsupported.', '');
    }
}
