<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Doctor\Project;

use Evolve\Core\Doctor\DoctorFinding;
use Evolve\Core\Doctor\DoctorStatus;
use Evolve\Core\Doctor\Project\EnvironmentVariablesCheck;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvironmentVariablesCheckTest extends TestCase
{
    public function test_it_exposes_the_exact_identifier(): void
    {
        $check = new EnvironmentVariablesCheck([]);

        self::assertSame('project.environment.variables', $check->identifier());
    }

    public function test_empty_requirement_list_passes(): void
    {
        $finding = (new EnvironmentVariablesCheck([]))->run();

        self::assertFinding(
            $finding,
            DoctorStatus::Pass,
            'No environment variables were required for this diagnostic check.',
        );
    }

    public function test_all_variables_present_passes(): void
    {
        $finding = (new EnvironmentVariablesCheck(
            ['APP_ENV', 'APP_KEY'],
            static fn(string $name): string|false => match ($name) {
                'APP_ENV' => 'production',
                'APP_KEY' => 'base64:key',
                default => false,
            },
        ))->run();

        self::assertFinding(
            $finding,
            DoctorStatus::Pass,
            'All required environment variables are present: APP_ENV, APP_KEY.',
        );
    }

    public function test_empty_string_environment_value_counts_as_present(): void
    {
        $finding = (new EnvironmentVariablesCheck(
            ['OPTIONAL_VALUE'],
            static fn(string $name) => '',
        ))->run();

        self::assertSame(DoctorStatus::Pass, self::statusOf($finding));
    }

    public function test_string_zero_environment_value_counts_as_present(): void
    {
        $finding = (new EnvironmentVariablesCheck(
            ['FEATURE_ENABLED'],
            static fn(string $name) => '0',
        ))->run();

        self::assertSame(DoctorStatus::Pass, self::statusOf($finding));
    }

    public function test_one_missing_variable_fails(): void
    {
        $finding = (new EnvironmentVariablesCheck(
            ['APP_ENV'],
            static fn(string $name) => false,
        ))->run();

        self::assertFinding(
            $finding,
            DoctorStatus::Fail,
            'Missing required environment variable: APP_ENV.',
            'Define the missing environment variable before running the application: APP_ENV.',
        );
    }

    public function test_multiple_missing_variables_preserve_supplied_order(): void
    {
        $finding = (new EnvironmentVariablesCheck(
            ['DATABASE_URL', 'APP_ENV', 'APP_KEY'],
            static fn(string $name): string|false => $name === 'APP_ENV' ? 'local' : false,
        ))->run();

        self::assertFinding(
            $finding,
            DoctorStatus::Fail,
            'Missing required environment variables: DATABASE_URL, APP_KEY.',
            'Define the missing environment variables before running the application: DATABASE_URL, APP_KEY.',
        );
    }

    public function test_remediation_contains_missing_names(): void
    {
        $finding = (new EnvironmentVariablesCheck(
            ['DATABASE_URL'],
            static fn(string $name) => false,
        ))->run();

        self::assertStringContainsString('DATABASE_URL', self::remediationOf($finding) ?? '');
    }

    public function test_environment_values_are_never_exposed_in_message(): void
    {
        $finding = (new EnvironmentVariablesCheck(
            ['APP_ENV', 'DATABASE_URL'],
            static fn(string $name): string|false => match ($name) {
                'APP_ENV' => 'super-secret-value',
                'DATABASE_URL' => false,
                default => false,
            },
        ))->run();

        self::assertStringNotContainsString('super-secret-value', self::messageOf($finding));
    }

    public function test_environment_values_are_never_exposed_in_remediation(): void
    {
        $finding = (new EnvironmentVariablesCheck(
            ['APP_ENV', 'DATABASE_URL'],
            static fn(string $name): string|false => match ($name) {
                'APP_ENV' => 'super-secret-value',
                'DATABASE_URL' => false,
                default => false,
            },
        ))->run();

        self::assertStringNotContainsString('super-secret-value', self::remediationOf($finding) ?? '');
    }

    public function test_injected_lookup_receives_names_in_supplied_order(): void
    {
        $seen = [];

        (new EnvironmentVariablesCheck(
            ['APP_ENV', 'APP_KEY', 'DATABASE_URL'],
            static function (string $name) use (&$seen) {
                $seen[] = $name;

                return 'present';
            },
        ))->run();

        self::assertSame(['APP_ENV', 'APP_KEY', 'DATABASE_URL'], $seen);
    }

    public function test_exact_duplicate_variable_declarations_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EnvironmentVariablesCheck(['APP_ENV', 'APP_ENV']);
    }

    public function test_case_different_variable_names_are_allowed_as_distinct(): void
    {
        $check = new EnvironmentVariablesCheck(
            ['APP_ENV', 'app_env'],
            static fn(string $name) => 'present',
        );

        self::assertSame(DoctorStatus::Pass, self::statusOf($check->run()));
    }

    public function test_empty_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EnvironmentVariablesCheck(['']);
    }

    public function test_whitespace_only_name_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EnvironmentVariablesCheck(['   ']);
    }

    public function test_name_containing_whitespace_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EnvironmentVariablesCheck(['APP ENV']);
    }

    public function test_name_containing_equals_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EnvironmentVariablesCheck(['APP_ENV=production']);
    }

    public function test_name_containing_nul_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EnvironmentVariablesCheck(["APP\0ENV"]);
    }

    public function test_non_string_entry_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EnvironmentVariablesCheck(['APP_ENV', 123]);
    }

    public function test_non_list_input_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EnvironmentVariablesCheck(['first' => 'APP_ENV']);
    }

    public function test_lookup_throwable_propagates(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('lookup failed');

        (new EnvironmentVariablesCheck(
            ['APP_ENV'],
            static fn(string $name) => throw new RuntimeException('lookup failed'),
        ))->run();
    }

    private static function assertFinding(
        DoctorFinding $finding,
        DoctorStatus $status,
        string $message,
        ?string $remediation = null,
    ): void {
        self::assertSame(EnvironmentVariablesCheck::IDENTIFIER, self::identifierOf($finding));
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
