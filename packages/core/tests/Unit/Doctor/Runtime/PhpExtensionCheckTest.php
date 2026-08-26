<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Doctor\Runtime;

use Evolve\Core\Doctor\DoctorStatus;
use Evolve\Core\Doctor\Runtime\PhpExtensionCheck;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PhpExtensionCheckTest extends TestCase
{
    public function testIdentifierIsRuntimePhpExtensions(): void
    {
        self::assertSame('runtime.php.extensions', (new PhpExtensionCheck([]))->identifier());
    }

    public function testEmptyRequirementPasses(): void
    {
        $finding = (new PhpExtensionCheck([], static fn(string $extension): bool => false))->run();

        self::assertSame(DoctorStatus::Pass, $finding->status());
    }

    public function testAllLoadedPasses(): void
    {
        $finding = (new PhpExtensionCheck(
            ['json', 'mbstring'],
            static fn(string $extension): bool => in_array($extension, ['json', 'mbstring'], true),
        ))->run();

        self::assertSame(DoctorStatus::Pass, $finding->status());
    }

    public function testOneMissingFails(): void
    {
        $finding = (new PhpExtensionCheck(
            ['json', 'mbstring'],
            static fn(string $extension): bool => $extension === 'json',
        ))->run();

        self::assertSame(DoctorStatus::Fail, $finding->status());
        self::assertStringContainsString('mbstring', $finding->message());
        self::assertStringContainsString('mbstring', (string) $finding->remediation());
    }

    public function testMultipleMissingFail(): void
    {
        $finding = (new PhpExtensionCheck(
            ['json', 'mbstring', 'pdo'],
            static fn(string $extension): bool => $extension === 'json',
        ))->run();

        self::assertSame(DoctorStatus::Fail, $finding->status());
        self::assertStringContainsString('mbstring, pdo', $finding->message());
    }

    public function testMissingOrderIsDeterministic(): void
    {
        $finding = (new PhpExtensionCheck(
            ['pdo', 'json', 'mbstring'],
            static fn(string $extension): bool => $extension === 'json',
        ))->run();

        self::assertStringContainsString('pdo, mbstring', $finding->message());
    }

    public function testExplicitLookupCallbackReceivesNames(): void
    {
        $received = [];

        (new PhpExtensionCheck(
            ['json', 'mbstring'],
            function (string $extension) use (&$received): bool {
                $received[] = $extension;

                return true;
            },
        ))->run();

        self::assertSame(['json', 'mbstring'], $received);
    }

    public function testDuplicateRequirementsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PhpExtensionCheck(['json', 'json']);
    }

    public function testDifferentlyCasedDuplicateRequirementsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PhpExtensionCheck(['json', 'JSON']);
    }

    public function testEmptyExtensionNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PhpExtensionCheck(['json', '']);
    }

    public function testNonListInputIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PhpExtensionCheck(['first' => 'json']);
    }
}
