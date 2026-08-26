<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Doctor\Runtime;

use Evolve\Core\Doctor\DoctorStatus;
use Evolve\Core\Doctor\Runtime\PhpVersionCheck;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PhpVersionCheckTest extends TestCase
{
    public function testIdentifierIsRuntimePhpVersion(): void
    {
        self::assertSame('runtime.php.version', (new PhpVersionCheck('8.4.0'))->identifier());
    }

    public function testPhp83FailsMinimumRuntimeCompatibility(): void
    {
        $finding = (new PhpVersionCheck('8.3.99'))->run();

        self::assertSame(DoctorStatus::Fail, $finding->status());
        self::assertSame('runtime.php.version', $finding->identifier());
    }

    public function testPhp84PassesMinimumRuntimeCompatibility(): void
    {
        $finding = (new PhpVersionCheck('8.4.0'))->run();

        self::assertSame(DoctorStatus::Pass, $finding->status());
    }

    public function testPhp85PassesMinimumRuntimeCompatibility(): void
    {
        $finding = (new PhpVersionCheck('8.5.0'))->run();

        self::assertSame(DoctorStatus::Pass, $finding->status());
    }

    public function testLaterVersionSatisfyingMinimumPasses(): void
    {
        $finding = (new PhpVersionCheck('9.0.0'))->run();

        self::assertSame(DoctorStatus::Pass, $finding->status());
    }

    public function testActualAndMinimumVersionsAppearInResult(): void
    {
        $finding = (new PhpVersionCheck('8.4.3', '8.4.0'))->run();

        self::assertStringContainsString('8.4.3', $finding->message());
        self::assertStringContainsString('8.4.0', $finding->message());
    }

    public function testFailingResultProvidesRemediation(): void
    {
        $finding = (new PhpVersionCheck('8.3.99', '8.4.0'))->run();

        self::assertSame(DoctorStatus::Fail, $finding->status());
        self::assertNotNull($finding->remediation());
        self::assertStringContainsString('8.4.0', $finding->remediation());
    }

    public function testMalformedExplicitVersionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PhpVersionCheck('not-a-version');
    }
}
