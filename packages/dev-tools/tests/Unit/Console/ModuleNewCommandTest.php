<?php

declare(strict_types=1);

namespace Evolve\DevTools\Tests\Unit\Console;

use Evolve\Core\Console\CommandInput;
use Evolve\DevTools\Console\ModuleNewCommand;
use Evolve\Module\ModuleDefinition;
use Evolve\Testing\Console\RecordingCommandOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleNewCommandTest extends TestCase
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

    public function testCommandMetadataIsExact(): void
    {
        $command = new ModuleNewCommand($this->projectRoot);

        self::assertTrue((new \ReflectionClass(ModuleNewCommand::class))->isFinal());
        self::assertSame('module:new', $command->name());
        self::assertSame('Create an application module scaffold.', $command->description());
    }

    public function testItCreatesModuleFilesWithDeterministicOutput(): void
    {
        $output = new RecordingCommandOutput();
        $result = (new ModuleNewCommand($this->projectRoot))->execute(new CommandInput(['Billing']), $output);

        self::assertSame(0, $result->exitCode());
        self::assertSame([
            'Created module app/billing.',
            'src/Modules/Billing/BillingModule.php',
            'src/Modules/Billing/module.php',
            'tests/Modules/Billing/BillingModuleTest.php',
        ], $output->lines());
        self::assertSame([], $output->errorLines());

        $this->assertFileContains('src/Modules/Billing/BillingModule.php', 'final class BillingModule implements Module');
        $this->assertFileContains('src/Modules/Billing/module.php', "new ComponentIdentifier('app/billing')");
        $this->assertFileContains('src/Modules/Billing/module.php', "'Billing'");
        $this->assertFileContains('tests/Modules/Billing/BillingModuleTest.php', 'assertInstanceOf(Module::class');

        require_once $this->projectPath('src/Modules/Billing/BillingModule.php');

        $definition = require $this->projectPath('src/Modules/Billing/module.php');

        self::assertInstanceOf(ModuleDefinition::class, $definition);
        self::assertSame('app/billing', $definition->identifier()->value());
        $definition->validate();
    }

    public function testAuditLogNameUsesKebabCaseIdentifierAndPreservesStudlyClassName(): void
    {
        $output = new RecordingCommandOutput();
        $result = (new ModuleNewCommand($this->projectRoot))->execute(new CommandInput(['AuditLog']), $output);

        self::assertSame(0, $result->exitCode());
        self::assertFileExists($this->projectPath('src/Modules/AuditLog/AuditLogModule.php'));
        $this->assertFileContains('src/Modules/AuditLog/module.php', "new ComponentIdentifier('app/audit-log')");
        self::assertSame('Created module app/audit-log.', $output->lines()[0]);
    }

    /**
     * @param list<string> $tokens
     */
    #[DataProvider('invalidNames')]
    public function testInvalidUsageReturnsUsageErrorOnly(array $tokens): void
    {
        $output = new RecordingCommandOutput();
        $result = (new ModuleNewCommand($this->projectRoot))->execute(new CommandInput($tokens), $output);

        self::assertSame(2, $result->exitCode());
        self::assertSame([], $output->lines());
        self::assertSame(['Usage: module:new <StudlyName>'], $output->errorLines());
        self::assertSame([], $this->projectFiles());
    }

    public function testItRefusesToOverwriteExistingFilesWithoutWritingOtherTargets(): void
    {
        $existingPath = $this->projectPath('src/Modules/Billing/BillingModule.php');
        self::assertTrue(mkdir(dirname($existingPath), 0777, true));
        self::assertSame(8, file_put_contents($existingPath, 'existing'));

        $output = new RecordingCommandOutput();
        $result = (new ModuleNewCommand($this->projectRoot))->execute(new CommandInput(['Billing']), $output);

        self::assertSame(1, $result->exitCode());
        self::assertSame([], $output->lines());
        self::assertSame(
            ['Refusing to overwrite existing file: src/Modules/Billing/BillingModule.php'],
            $output->errorLines(),
        );
        self::assertSame('existing', file_get_contents($existingPath));
        self::assertFileDoesNotExist($this->projectPath('src/Modules/Billing/module.php'));
        self::assertFileDoesNotExist($this->projectPath('tests/Modules/Billing/BillingModuleTest.php'));
    }

    public function testRepeatGenerationRefusesExistingScaffold(): void
    {
        $command = new ModuleNewCommand($this->projectRoot);
        $firstOutput = new RecordingCommandOutput();
        $secondOutput = new RecordingCommandOutput();

        self::assertSame(0, $command->execute(new CommandInput(['Billing']), $firstOutput)->exitCode());

        $result = $command->execute(new CommandInput(['Billing']), $secondOutput);

        self::assertSame(1, $result->exitCode());
        self::assertSame([], $secondOutput->lines());
        self::assertSame(
            ['Refusing to overwrite existing file: src/Modules/Billing/BillingModule.php'],
            $secondOutput->errorLines(),
        );
    }

    public function testSymlinkTraversalOutsideProjectRootIsRejectedWhenSupported(): void
    {
        $outsideRoot = $this->createProjectRoot();

        try {
            self::assertTrue(mkdir($this->projectPath('src'), 0777, true));

            if (! @symlink($outsideRoot, $this->projectPath('src/Modules'))) {
                self::markTestSkipped('Symlink creation is not available in this environment.');
            }

            $output = new RecordingCommandOutput();
            $result = (new ModuleNewCommand($this->projectRoot))->execute(new CommandInput(['Billing']), $output);

            self::assertSame(1, $result->exitCode());
            self::assertSame([], $output->lines());
            self::assertSame(['Refusing to write outside project root.'], $output->errorLines());
            self::assertFileDoesNotExist($outsideRoot . DIRECTORY_SEPARATOR . 'Billing' . DIRECTORY_SEPARATOR . 'BillingModule.php');
        } finally {
            $this->removeDirectory($outsideRoot);
        }
    }

    /**
     * @return iterable<string, array{0: list<string>}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [[]];
        yield 'extra token' => [['Billing', 'Extra']];
        yield 'lowercase first' => [['billing']];
        yield 'digit leading' => [['2Billing']];
        yield 'whitespace' => [["Bill ing"]];
        yield 'slash' => [['../Billing']];
        yield 'backslash' => [['Vendor\\Billing']];
        yield 'dot' => [['Billing.Module']];
        yield 'shell metacharacter' => [['Billing;rm']];
    }

    private function assertFileContains(string $relativePath, string $needle): void
    {
        $content = file_get_contents($this->projectPath($relativePath));

        self::assertIsString($content);
        self::assertStringContainsString($needle, $content);
    }

    private function createProjectRoot(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evolvephp-dev-tools-test-' . bin2hex(random_bytes(8));

        self::assertTrue(mkdir($path, 0777, true));

        return realpath($path) ?: $path;
    }

    /**
     * @return list<string>
     */
    private function projectFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($this->projectRoot) + 1));
            }
        }

        sort($files);

        return $files;
    }

    private function projectPath(string $relativePath): string
    {
        return $this->projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
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
