<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Doctor\Project;

use Evolve\Core\Doctor\DoctorFinding;
use Evolve\Core\Doctor\DoctorStatus;
use Evolve\Core\Doctor\Project\WritablePathsCheck;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WritablePathsCheckTest extends TestCase
{
    public function test_it_exposes_the_exact_identifier(): void
    {
        $check = new WritablePathsCheck([]);

        self::assertSame('project.paths.writable', $check->identifier());
    }

    public function test_empty_requirement_list_passes(): void
    {
        $finding = (new WritablePathsCheck([]))->run();

        self::assertFinding(
            $finding,
            DoctorStatus::Pass,
            'No writable paths were required for this diagnostic check.',
        );
    }

    public function test_all_paths_writable_passes(): void
    {
        $finding = (new WritablePathsCheck(
            ['storage/cache', 'storage/logs'],
            static fn(string $path): bool => true,
        ))->run();

        self::assertFinding(
            $finding,
            DoctorStatus::Pass,
            'All required paths are writable: storage/cache, storage/logs.',
        );
    }

    public function test_one_non_writable_path_fails(): void
    {
        $finding = (new WritablePathsCheck(
            ['storage/cache'],
            static fn(string $path): bool => false,
        ))->run();

        self::assertFinding(
            $finding,
            DoctorStatus::Fail,
            'Required path is not writable: storage/cache.',
            'Ensure the required path is writable by the PHP process: storage/cache.',
        );
    }

    public function test_multiple_non_writable_paths_preserve_supplied_order(): void
    {
        $finding = (new WritablePathsCheck(
            ['storage/cache', 'storage/logs', 'var/tmp'],
            static fn(string $path): bool => $path === 'storage/logs',
        ))->run();

        self::assertFinding(
            $finding,
            DoctorStatus::Fail,
            'Required paths are not writable: storage/cache, var/tmp.',
            'Ensure the required paths are writable by the PHP process: storage/cache, var/tmp.',
        );
    }

    public function test_remediation_contains_affected_paths(): void
    {
        $finding = (new WritablePathsCheck(
            ['storage/cache'],
            static fn(string $path): bool => false,
        ))->run();

        self::assertStringContainsString('storage/cache', self::remediationOf($finding) ?? '');
    }

    public function test_injected_lookup_receives_paths_in_supplied_order(): void
    {
        $seen = [];

        (new WritablePathsCheck(
            ['storage/cache', 'storage/logs', 'var/tmp'],
            static function (string $path) use (&$seen): bool {
                $seen[] = $path;

                return true;
            },
        ))->run();

        self::assertSame(['storage/cache', 'storage/logs', 'var/tmp'], $seen);
    }

    public function test_nonexistent_default_style_lookup_failure_is_represented_as_fail(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evolvephp-doctor-missing-' . bin2hex(random_bytes(8));

        $finding = (new WritablePathsCheck([$path]))->run();

        self::assertSame(DoctorStatus::Fail, self::statusOf($finding));
        self::assertStringContainsString($path, self::messageOf($finding));
    }

    public function test_exact_duplicate_path_declarations_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WritablePathsCheck(['storage/cache', 'storage/cache']);
    }

    public function test_empty_path_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WritablePathsCheck(['']);
    }

    public function test_whitespace_only_path_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WritablePathsCheck(['   ']);
    }

    public function test_path_containing_nul_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WritablePathsCheck(["storage\0cache"]);
    }

    public function test_uri_or_stream_wrapper_path_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WritablePathsCheck(['php://memory']);
    }

    public function test_non_string_entry_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WritablePathsCheck(['storage/cache', 123]);
    }

    public function test_non_list_input_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WritablePathsCheck(['cache' => 'storage/cache']);
    }

    public function test_callback_throwable_propagates(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('writability failed');

        (new WritablePathsCheck(
            ['storage/cache'],
            static fn(string $path): bool => throw new RuntimeException('writability failed'),
        ))->run();
    }

    private static function assertFinding(
        DoctorFinding $finding,
        DoctorStatus $status,
        string $message,
        ?string $remediation = null,
    ): void {
        self::assertSame(WritablePathsCheck::IDENTIFIER, self::identifierOf($finding));
        self::assertSame($status, self::statusOf($finding));
        self::assertSame($message, self::messageOf($finding));
        self::assertSame($remediation, self::remediationOf($finding));
    }

    private static function identifierOf(DoctorFinding $finding): string
    {
        return $finding->identifier();
    }

    private static function statusOf(DoctorFinding $finding): DoctorStatus
    {
        return $finding->status();
    }

    private static function messageOf(DoctorFinding $finding): string
    {
        return $finding->message();
    }

    private static function remediationOf(DoctorFinding $finding): ?string
    {
        return $finding->remediation();
    }
}
