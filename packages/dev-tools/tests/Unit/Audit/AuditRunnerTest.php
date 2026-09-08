<?php

declare(strict_types=1);

namespace Evolve\DevTools\Tests\Unit\Audit;

use Evolve\DevTools\Audit\AuditFinding;
use Evolve\DevTools\Audit\AuditInspector;
use Evolve\DevTools\Audit\AuditReport;
use Evolve\DevTools\Audit\AuditRunner;
use Evolve\DevTools\Audit\AuditSeverity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AuditRunnerTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = $this->createProjectRoot();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    public function testSeverityValuesAreExact(): void
    {
        $reflection = new \ReflectionEnum(AuditSeverity::class);
        $values = array_map(
            static fn(\ReflectionEnumBackedCase $case): int|string => $case->getBackingValue(),
            $reflection->getCases(),
        );

        self::assertSame(['info', 'warning', 'risk'], $values);
    }

    public function testFindingIdentifierValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Audit finding identifier is invalid.');

        new AuditFinding('Invalid Identifier', AuditSeverity::Info, 'Observed evidence.', []);
    }

    public function testFindingMessageValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Audit finding message must be non-empty.');

        new AuditFinding('composer.php', AuditSeverity::Info, '   ', []);
    }

    public function testEvidenceRemainsPlainData(): void
    {
        $finding = new AuditFinding('composer.php', AuditSeverity::Info, 'Observed evidence.', [
            'php' => '^8.4',
            'present' => true,
            'nested' => ['value' => null],
        ]);

        self::assertSame(['php' => '^8.4', 'present' => true, 'nested' => ['value' => null]], $finding->evidence());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Audit finding evidence must contain only plain data.');

        new AuditFinding('composer.object', AuditSeverity::Risk, 'Object evidence.', ['object' => new \stdClass()]);
    }

    public function testReportRejectsNonFindings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Audit reports may contain only audit findings.');

        $report = new AuditReport([new AuditFinding('valid', AuditSeverity::Info, 'Valid.', []), 'not-a-finding']);

        self::assertCount(0, $report);
    }

    public function testExplicitInspectorListAndOrderArePreserved(): void
    {
        $runner = new AuditRunner([
            new FixedInspector(new AuditFinding('first', AuditSeverity::Info, 'First.', [])),
            new FixedInspector(new AuditFinding('second', AuditSeverity::Warning, 'Second.', [])),
        ]);

        self::assertSame(['first', 'second'], $this->findingIds($runner->inspect($this->projectRoot)));
    }

    public function testFindingOrderInsideInspectorOutputIsPreserved(): void
    {
        $runner = new AuditRunner([
            new FixedInspector(
                new AuditFinding('first', AuditSeverity::Info, 'First.', []),
                new AuditFinding('second', AuditSeverity::Warning, 'Second.', []),
            ),
        ]);

        self::assertSame(['first', 'second'], $this->findingIds($runner->inspect($this->projectRoot)));
    }

    public function testInvalidTargetRootIsRejectedBeforeInspection(): void
    {
        $runner = new AuditRunner([new ExplodingInspector()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Audit target root must be an existing directory.');

        $runner->inspect($this->projectRoot . DIRECTORY_SEPARATOR . 'missing');
    }

    public function testMalformedInspectorCollectionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Audit runner accepts only audit inspectors.');

        $runner = new AuditRunner([new FixedInspector(), 'not-an-inspector']);

        self::assertCount(0, $runner->inspect($this->projectRoot));
    }

    public function testInvalidInspectorOutputIsRejected(): void
    {
        $runner = new AuditRunner([new InvalidOutputInspector()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Audit inspectors must return iterable findings.');

        $runner->inspect($this->projectRoot);
    }

    public function testIterableInspectorOutputContainingNonFindingIsRejected(): void
    {
        $runner = new AuditRunner([new InvalidIterableOutputInspector()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Audit inspectors must return audit findings.');

        $runner->inspect($this->projectRoot);
    }

    /**
     * @return list<string>
     */
    private function findingIds(AuditReport $report): array
    {
        return array_map(
            static fn(AuditFinding $finding): string => $finding->identifier(),
            $report->findings(),
        );
    }

    private function createProjectRoot(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evolvephp-audit-runner-test-' . bin2hex(random_bytes(8));

        self::assertTrue(mkdir($path, 0777, true));

        return realpath($path) ?: $path;
    }

    private function removeDirectory(string $path): void
    {
        $real = realpath($path);

        if ($real === false) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            $entryPath = $entry->getPathname();

            if ($entry->isDir() && ! $entry->isLink()) {
                rmdir($entryPath);
                continue;
            }

            unlink($entryPath);
        }

        rmdir($real);
    }
}

final readonly class FixedInspector implements AuditInspector
{
    /**
     * @var list<AuditFinding>
     */
    private array $findings;

    public function __construct(AuditFinding ...$findings)
    {
        $this->findings = $findings;
    }

    public function inspect(string $projectRoot): mixed
    {
        return $this->findings;
    }
}

final readonly class ExplodingInspector implements AuditInspector
{
    public function inspect(string $projectRoot): mixed
    {
        throw new \RuntimeException('Inspector should not run.');
    }
}

final readonly class InvalidOutputInspector implements AuditInspector
{
    public function inspect(string $projectRoot): mixed
    {
        return 'not-iterable';
    }
}

final readonly class InvalidIterableOutputInspector implements AuditInspector
{
    public function inspect(string $projectRoot): mixed
    {
        return [
            new AuditFinding('valid', AuditSeverity::Info, 'Valid.', []),
            'not-a-finding',
        ];
    }
}
