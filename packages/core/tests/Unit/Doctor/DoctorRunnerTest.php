<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Doctor;

use Closure;
use Evolve\Core\Doctor\DoctorCheck;
use Evolve\Core\Doctor\DoctorFinding;
use Evolve\Core\Doctor\DoctorRunner;
use Evolve\Core\Doctor\DoctorStatus;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class DoctorRunnerTest extends TestCase
{
    public function testEmptyCheckSetProducesSuccessfulEmptyReport(): void
    {
        $report = (new DoctorRunner([]))->run();

        self::assertSame([], $report->findings());
        self::assertTrue($report->successful());
        self::assertFalse($report->hasWarnings());
        self::assertFalse($report->hasFailures());
    }

    public function testOneCheckProducesOneFinding(): void
    {
        $report = (new DoctorRunner([
            $this->check('runtime.php.version', DoctorStatus::Pass),
        ]))->run();

        self::assertCount(1, $report->findings());
        self::assertSame('runtime.php.version', $report->findings()[0]->identifier());
    }

    public function testRegistrationOrderIsPreserved(): void
    {
        $report = (new DoctorRunner([
            $this->check('runtime.php.version', DoctorStatus::Pass),
            $this->check('runtime.php.extensions', DoctorStatus::Pass),
            $this->check('runtime.cache.writable', DoctorStatus::Pass),
        ]))->run();

        self::assertSame(
            ['runtime.php.version', 'runtime.php.extensions', 'runtime.cache.writable'],
            array_map(static fn(DoctorFinding $finding): string => $finding->identifier(), $report->findings()),
        );
    }

    public function testDuplicateIdentifierIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DoctorRunner([
            $this->check('runtime.php.version', DoctorStatus::Pass),
            $this->check('runtime.php.version', DoctorStatus::Pass),
        ]);
    }

    public function testMalformedCheckIdentifierIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DoctorRunner([
            $this->check('Runtime PHP Version', DoctorStatus::Pass),
        ]);
    }

    public function testPassOnlyReportIsSuccessful(): void
    {
        $report = (new DoctorRunner([
            $this->check('runtime.php.version', DoctorStatus::Pass),
            $this->check('runtime.php.extensions', DoctorStatus::Pass),
        ]))->run();

        self::assertTrue($report->successful());
        self::assertFalse($report->hasWarnings());
        self::assertFalse($report->hasFailures());
    }

    public function testWarningDoesNotFailReport(): void
    {
        $report = (new DoctorRunner([
            $this->check('runtime.php.version', DoctorStatus::Warning),
        ]))->run();

        self::assertTrue($report->successful());
        self::assertTrue($report->hasWarnings());
        self::assertFalse($report->hasFailures());
    }

    public function testFailMakesReportUnsuccessful(): void
    {
        $report = (new DoctorRunner([
            $this->check('runtime.php.version', DoctorStatus::Fail),
        ]))->run();

        self::assertFalse($report->successful());
        self::assertFalse($report->hasWarnings());
        self::assertTrue($report->hasFailures());
    }

    public function testMixedStatusesPreserveOrder(): void
    {
        $report = (new DoctorRunner([
            $this->check('runtime.php.version', DoctorStatus::Pass),
            $this->check('runtime.php.extensions', DoctorStatus::Warning),
            $this->check('runtime.cache.writable', DoctorStatus::Fail),
        ]))->run();

        self::assertSame(
            [DoctorStatus::Pass, DoctorStatus::Warning, DoctorStatus::Fail],
            array_map(static fn(DoctorFinding $finding): DoctorStatus => $finding->status(), $report->findings()),
        );
    }

    public function testChecksContinueAfterNormalFailFinding(): void
    {
        $ran = [];
        $recordRun = static function (string $identifier) use (&$ran): void {
            $ran[] = $identifier;
        };

        $report = (new DoctorRunner([
            $this->check('runtime.php.version', DoctorStatus::Fail, $recordRun),
            $this->check('runtime.php.extensions', DoctorStatus::Pass, $recordRun),
        ]))->run();

        self::assertSame(['runtime.php.version', 'runtime.php.extensions'], $ran);
        self::assertCount(2, $report->findings());
    }

    public function testReturnedFindingIdentifierMismatchIsRejected(): void
    {
        $this->expectException(LogicException::class);

        (new DoctorRunner([
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
        ]))->run();
    }

    /**
     * @param (Closure(string): void)|null $onRun
     */
    private function check(string $identifier, DoctorStatus $status, ?Closure $onRun = null): DoctorCheck
    {
        return new class ($identifier, $status, $onRun) implements DoctorCheck {
            public function __construct(
                private readonly string $identifier,
                private readonly DoctorStatus $status,
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
                    sprintf('Diagnostic %s completed.', $this->identifier),
                );
            }
        };
    }
}
