<?php

declare(strict_types=1);

namespace Evolve\DevTools\Tests\Unit\Audit\Console;

use Evolve\Core\Console\CommandInput;
use Evolve\DevTools\Audit\AuditFinding;
use Evolve\DevTools\Audit\AuditInspector;
use Evolve\DevTools\Audit\AuditRunner;
use Evolve\DevTools\Audit\AuditSeverity;
use Evolve\DevTools\Audit\Console\AuditCommand;
use Evolve\DevTools\Audit\Presentation\AuditReportRenderer;
use Evolve\DevTools\Audit\Project\ComposerProjectInspector;
use Evolve\Testing\Console\RecordingCommandOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditCommandTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = $this->createProjectRoot();
        file_put_contents($this->path('composer.json'), '{"require":{"php":"^8.1"}}');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectRoot);
    }

    public function testCommandMetadataIsExact(): void
    {
        $command = new AuditCommand(new AuditRunner([]), new AuditReportRenderer());

        self::assertTrue((new \ReflectionClass(AuditCommand::class))->isFinal());
        self::assertSame('audit', $command->name());
        self::assertSame('Run the experimental EvolvePHP project Audit.', $command->description());
    }

    public function testDefaultFormatIsText(): void
    {
        $output = new RecordingCommandOutput();
        $result = $this->command(new FixedInspector(new AuditFinding('audit.info', AuditSeverity::Info, 'Info evidence.', [])))
            ->execute(new CommandInput([$this->projectRoot]), $output);

        self::assertSame(0, $result->exitCode());
        self::assertStringStartsWith("Audit report\nFindings: 1\n", implode("\n", $output->lines()));
        self::assertSame([], $output->errorLines());
    }

    public function testTextFormatOptionIsAcceptedBeforeAndAfterTarget(): void
    {
        foreach ([['--format=text', $this->projectRoot], [$this->projectRoot, '--format=text']] as $tokens) {
            $output = new RecordingCommandOutput();
            $result = $this->command(new FixedInspector(new AuditFinding('audit.info', AuditSeverity::Info, 'Info evidence.', [])))
                ->execute(new CommandInput($tokens), $output);

            self::assertSame(0, $result->exitCode());
            self::assertStringContainsString('[info] audit.info: Info evidence.', implode("\n", $output->lines()));
        }
    }

    public function testJsonFormatOptionIsAcceptedBeforeAndAfterTarget(): void
    {
        foreach ([['--format=json', $this->projectRoot], [$this->projectRoot, '--format=json']] as $tokens) {
            $output = new RecordingCommandOutput();
            $result = $this->command(new FixedInspector(new AuditFinding('audit.info', AuditSeverity::Info, 'Info evidence.', [])))
                ->execute(new CommandInput($tokens), $output);

            self::assertSame(0, $result->exitCode());
            self::assertSame(1, json_decode(implode("\n", $output->lines()), true, flags: JSON_THROW_ON_ERROR)['schema_version']);
        }
    }

    /**
     * @param list<string> $tokens
     */
    #[DataProvider('invalidUsage')]
    public function testInvalidUsageReturnsExitTwoAndWritesOnlyErrorOutput(array $tokens): void
    {
        $output = new RecordingCommandOutput();
        $result = $this->command(new FixedInspector())->execute(new CommandInput($tokens), $output);

        self::assertSame(2, $result->exitCode());
        self::assertSame([], $output->lines());
        self::assertSame(['Usage: evolve-audit <target-root> [--format=text|--format=json]'], $output->errorLines());
    }

    public function testInvalidTargetReturnsExitTwoAndWritesErrorOutput(): void
    {
        $output = new RecordingCommandOutput();
        $result = $this->command(new ExplodingInspector())
            ->execute(new CommandInput([$this->projectRoot . DIRECTORY_SEPARATOR . 'missing']), $output);

        self::assertSame(2, $result->exitCode());
        self::assertSame([], $output->lines());
        self::assertSame(['Audit target root must be an existing directory.'], $output->errorLines());
    }

    #[DataProvider('successfulSeverities')]
    public function testFindingsAreEvidenceAndDoNotChangeSuccessfulExit(AuditSeverity $severity): void
    {
        $output = new RecordingCommandOutput();
        $result = $this->command(new FixedInspector(new AuditFinding('audit.finding', $severity, 'Evidence.', [])))
            ->execute(new CommandInput([$this->projectRoot]), $output);

        self::assertSame(0, $result->exitCode());
        self::assertSame([], $output->errorLines());
    }

    public function testMalformedTargetEvidenceRemainsRenderableAndExitsZero(): void
    {
        file_put_contents($this->path('composer.json'), '{"require":');

        $output = new RecordingCommandOutput();
        $result = $this->command(new ComposerProjectInspector())->execute(new CommandInput([$this->projectRoot, '--format=json']), $output);
        $decoded = json_decode(implode("\n", $output->lines()), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(0, $result->exitCode());
        self::assertSame('composer_json.invalid_json', $decoded['findings'][0]['identifier']);
    }

    public function testInspectorOutputOrderIsNotResorted(): void
    {
        $output = new RecordingCommandOutput();
        $this->command(new FixedInspector(
            new AuditFinding('zeta.finding', AuditSeverity::Risk, 'Zeta.', []),
            new AuditFinding('alpha.finding', AuditSeverity::Info, 'Alpha.', []),
        ))->execute(new CommandInput([$this->projectRoot, '--format=json']), $output);

        self::assertSame(['zeta.finding', 'alpha.finding'], array_column(json_decode(implode("\n", $output->lines()), true, flags: JSON_THROW_ON_ERROR)['findings'], 'identifier'));
    }

    /**
     * @return iterable<string, array{0: list<string>}>
     */
    public static function invalidUsage(): iterable
    {
        yield 'missing target' => [[]];
        yield 'extra target' => [['one', 'two']];
        yield 'unknown option' => [['--verbose', 'target']];
        yield 'unsupported format' => [['target', '--format=yaml']];
        yield 'duplicate format' => [['--format=text', 'target', '--format=json']];
    }

    /**
     * @return iterable<string, array{0: AuditSeverity}>
     */
    public static function successfulSeverities(): iterable
    {
        yield 'info' => [AuditSeverity::Info];
        yield 'warning' => [AuditSeverity::Warning];
        yield 'risk' => [AuditSeverity::Risk];
    }

    private function command(AuditInspector ...$inspectors): AuditCommand
    {
        return new AuditCommand(new AuditRunner($inspectors), new AuditReportRenderer());
    }

    private function path(string $relativePath): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function createProjectRoot(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evolvephp-audit-command-test-' . bin2hex(random_bytes(8));

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
