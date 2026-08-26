<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Doctor\Console;

use Closure;
use Evolve\Core\Console\CommandInput;
use Evolve\Core\Console\CommandOutput;
use Evolve\Core\Doctor\Console\DoctorCommand;
use Evolve\Core\Doctor\DoctorCheck;
use Evolve\Core\Doctor\DoctorFinding;
use Evolve\Core\Doctor\DoctorRunner;
use Evolve\Core\Doctor\DoctorStatus;
use LogicException;
use PHPUnit\Framework\TestCase;

final class DoctorCommandTest extends TestCase
{
    public function testCommandNameIsDoctor(): void
    {
        self::assertSame('doctor', $this->command([])->name());
    }

    public function testCommandDescriptionIsStable(): void
    {
        self::assertSame(
            'Run configured Evolve Doctor diagnostic checks.',
            $this->command([])->description(),
        );
    }

    public function testPassFindingRendersNormalOutputAndReturnsSuccess(): void
    {
        $output = new RecordingCommandOutput();

        $result = $this->command([
            $this->check('runtime.php.version', DoctorStatus::Pass, 'PHP version is supported.'),
        ])->execute(new CommandInput([]), $output);

        self::assertSame(0, $result->exitCode());
        self::assertSame(['[PASS] runtime.php.version: PHP version is supported.'], $output->lines());
        self::assertSame([], $output->errorLines());
    }

    public function testWarningFindingRendersNormalOutputAndReturnsSuccess(): void
    {
        $output = new RecordingCommandOutput();

        $result = $this->command([
            $this->check('runtime.cache', DoctorStatus::Warning, 'Cache directory could not be verified.'),
        ])->execute(new CommandInput([]), $output);

        self::assertSame(0, $result->exitCode());
        self::assertSame(['[WARNING] runtime.cache: Cache directory could not be verified.'], $output->lines());
        self::assertSame([], $output->errorLines());
    }

    public function testFailFindingRendersNormalOutputAndReturnsFailureStatus(): void
    {
        $output = new RecordingCommandOutput();

        $result = $this->command([
            $this->check('runtime.php.extensions', DoctorStatus::Fail, 'Missing required PHP extension: mbstring.'),
        ])->execute(new CommandInput([]), $output);

        self::assertSame(1, $result->exitCode());
        self::assertSame(['[FAIL] runtime.php.extensions: Missing required PHP extension: mbstring.'], $output->lines());
        self::assertSame([], $output->errorLines());
    }

    public function testFindingWithRemediationEmitsFindingThenRemediationAsNormalOutput(): void
    {
        $output = new RecordingCommandOutput();

        $this->command([
            $this->check(
                'runtime.php.extensions',
                DoctorStatus::Fail,
                'Missing required PHP extension: mbstring.',
                'Install or enable the missing PHP extension: mbstring.',
            ),
        ])->execute(new CommandInput([]), $output);

        self::assertSame([
            '[FAIL] runtime.php.extensions: Missing required PHP extension: mbstring.',
            'Remediation: Install or enable the missing PHP extension: mbstring.',
        ], $output->lines());
        self::assertSame([], $output->errorLines());
    }

    public function testFindingWithoutRemediationEmitsNoRemediationLine(): void
    {
        $output = new RecordingCommandOutput();

        $this->command([
            $this->check('runtime.php.version', DoctorStatus::Pass, 'PHP version is supported.'),
        ])->execute(new CommandInput([]), $output);

        self::assertSame(['[PASS] runtime.php.version: PHP version is supported.'], $output->lines());
    }

    public function testMultipleFindingsPreserveReportOrderWithoutGrouping(): void
    {
        $output = new RecordingCommandOutput();

        $this->command([
            $this->check('runtime.php.version', DoctorStatus::Pass, 'PHP version is supported.'),
            $this->check('runtime.cache', DoctorStatus::Warning, 'Cache directory could not be verified.'),
            $this->check('runtime.php.extensions', DoctorStatus::Fail, 'Missing required PHP extension: mbstring.'),
        ])->execute(new CommandInput([]), $output);

        self::assertSame([
            '[PASS] runtime.php.version: PHP version is supported.',
            '[WARNING] runtime.cache: Cache directory could not be verified.',
            '[FAIL] runtime.php.extensions: Missing required PHP extension: mbstring.',
        ], $output->lines());
    }

    public function testUnsupportedInputTokenReturnsUsageErrorWithoutNormalOutput(): void
    {
        $output = new RecordingCommandOutput();

        $result = $this->command([
            $this->check('runtime.php.version', DoctorStatus::Pass, 'PHP version is supported.'),
        ])->execute(new CommandInput(['--json']), $output);

        self::assertSame(2, $result->exitCode());
        self::assertSame([], $output->lines());
        self::assertSame(['The doctor command does not accept arguments or options.'], $output->errorLines());
    }

    public function testMultipleUnsupportedInputTokensReturnUsageErrorWithoutNormalOutput(): void
    {
        $output = new RecordingCommandOutput();

        $result = $this->command([
            $this->check('runtime.php.version', DoctorStatus::Pass, 'PHP version is supported.'),
        ])->execute(new CommandInput(['--json', '--verbose']), $output);

        self::assertSame(2, $result->exitCode());
        self::assertSame([], $output->lines());
        self::assertSame(['The doctor command does not accept arguments or options.'], $output->errorLines());
    }

    public function testUnsupportedInputPreventsDoctorChecksFromRunning(): void
    {
        $ran = false;
        $output = new RecordingCommandOutput();

        $this->command([
            $this->check(
                'runtime.php.version',
                DoctorStatus::Pass,
                'PHP version is supported.',
                null,
                static function (string $_identifier) use (&$ran): void {
                    $ran = true;
                },
            ),
        ])->execute(new CommandInput(['--json']), $output);

        self::assertFalse($ran);
    }

    public function testEmptyDoctorRunnerProducesNoOutputAndReturnsSuccess(): void
    {
        $output = new RecordingCommandOutput();

        $result = $this->command([])->execute(new CommandInput([]), $output);

        self::assertSame(0, $result->exitCode());
        self::assertSame([], $output->lines());
        self::assertSame([], $output->errorLines());
    }

    public function testMalformedDoctorExecutionFailurePropagates(): void
    {
        $this->expectException(LogicException::class);

        $this->command([
            new class implements DoctorCheck {
                public function identifier(): string
                {
                    return 'runtime.php.version';
                }

                public function run(): DoctorFinding
                {
                    return new DoctorFinding(
                        'runtime.php.extensions',
                        DoctorStatus::Pass,
                        'PHP extensions are loaded.',
                    );
                }
            },
        ])->execute(new CommandInput([]), new RecordingCommandOutput());
    }

    /**
     * @param iterable<DoctorCheck> $checks
     */
    private function command(iterable $checks): DoctorCommand
    {
        return new DoctorCommand(new DoctorRunner($checks));
    }

    /**
     * @param (Closure(string): void)|null $onRun
     */
    private function check(
        string $identifier,
        DoctorStatus $status,
        string $message,
        ?string $remediation = null,
        ?Closure $onRun = null,
    ): DoctorCheck {
        return new class ($identifier, $status, $message, $remediation, $onRun) implements DoctorCheck {
            public function __construct(
                private readonly string $identifier,
                private readonly DoctorStatus $status,
                private readonly string $message,
                private readonly ?string $remediation,
                private readonly ?Closure $onRun,
            ) {}

            public function identifier(): string
            {
                return $this->identifier;
            }

            public function run(): DoctorFinding
            {
                if ($this->onRun !== null) {
                    ($this->onRun)($this->identifier);
                }

                return new DoctorFinding(
                    $this->identifier,
                    $this->status,
                    $this->message,
                    $this->remediation,
                );
            }
        };
    }
}

final class RecordingCommandOutput implements CommandOutput
{
    /** @var list<string> */
    private array $lines = [];

    /** @var list<string> */
    private array $errorLines = [];

    public function write(string $message): void
    {
        $this->lines[] = $message;
    }

    public function writeError(string $message): void
    {
        $this->errorLines[] = $message;
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * @return list<string>
     */
    public function errorLines(): array
    {
        return $this->errorLines;
    }
}
