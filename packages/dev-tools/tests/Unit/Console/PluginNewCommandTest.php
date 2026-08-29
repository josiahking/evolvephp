<?php

declare(strict_types=1);

namespace Evolve\DevTools\Tests\Unit\Console;

use Evolve\Core\Console\CommandInput;
use Evolve\DevTools\Console\PluginNewCommand;
use Evolve\Plugin\PluginDefinition;
use Evolve\Testing\Console\RecordingCommandOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PluginNewCommandTest extends TestCase
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
        $command = new PluginNewCommand($this->projectRoot);

        self::assertTrue((new \ReflectionClass(PluginNewCommand::class))->isFinal());
        self::assertSame('plugin:new', $command->name());
        self::assertSame('Create a framework plugin scaffold.', $command->description());
    }

    public function testItCreatesPluginFilesWithDeterministicOutput(): void
    {
        $output = new RecordingCommandOutput();
        $result = (new PluginNewCommand($this->projectRoot))->execute(new CommandInput(['Cache']), $output);

        self::assertSame(0, $result->exitCode());
        self::assertSame([
            'Created plugin app/cache.',
            'src/Plugins/Cache/CachePlugin.php',
            'src/Plugins/Cache/plugin.php',
            'tests/Plugins/Cache/CachePluginTest.php',
        ], $output->lines());
        self::assertSame([], $output->errorLines());

        $this->assertFileContains('src/Plugins/Cache/CachePlugin.php', 'final class CachePlugin implements Plugin');
        $this->assertFileContains('src/Plugins/Cache/plugin.php', "new ComponentIdentifier('app/cache')");
        $this->assertFileContains('src/Plugins/Cache/plugin.php', "'Cache'");
        $this->assertFileContains('tests/Plugins/Cache/CachePluginTest.php', 'assertInstanceOf(Plugin::class');

        require_once $this->projectPath('src/Plugins/Cache/CachePlugin.php');

        $definition = require $this->projectPath('src/Plugins/Cache/plugin.php');

        self::assertInstanceOf(PluginDefinition::class, $definition);
        self::assertSame('app/cache', $definition->identifier()->value());
        $definition->validate();
    }

    public function testBilling2NameIsAcceptedAndPreserved(): void
    {
        $output = new RecordingCommandOutput();
        $result = (new PluginNewCommand($this->projectRoot))->execute(new CommandInput(['Billing2']), $output);

        self::assertSame(0, $result->exitCode());
        self::assertFileExists($this->projectPath('src/Plugins/Billing2/Billing2Plugin.php'));
        $this->assertFileContains('src/Plugins/Billing2/plugin.php', "new ComponentIdentifier('app/billing2')");
        self::assertSame('Created plugin app/billing2.', $output->lines()[0]);
    }

    /**
     * @param list<string> $tokens
     */
    #[DataProvider('invalidNames')]
    public function testInvalidUsageReturnsUsageErrorOnly(array $tokens): void
    {
        $output = new RecordingCommandOutput();
        $result = (new PluginNewCommand($this->projectRoot))->execute(new CommandInput($tokens), $output);

        self::assertSame(2, $result->exitCode());
        self::assertSame([], $output->lines());
        self::assertSame(['Usage: plugin:new <StudlyName>'], $output->errorLines());
        self::assertSame([], $this->projectFiles());
    }

    public function testItRefusesToOverwriteExistingFilesWithoutWritingOtherTargets(): void
    {
        $existingPath = $this->projectPath('src/Plugins/Cache/CachePlugin.php');
        self::assertTrue(mkdir(dirname($existingPath), 0777, true));
        self::assertSame(8, file_put_contents($existingPath, 'existing'));

        $output = new RecordingCommandOutput();
        $result = (new PluginNewCommand($this->projectRoot))->execute(new CommandInput(['Cache']), $output);

        self::assertSame(1, $result->exitCode());
        self::assertSame([], $output->lines());
        self::assertSame(
            ['Refusing to overwrite existing file: src/Plugins/Cache/CachePlugin.php'],
            $output->errorLines(),
        );
        self::assertSame('existing', file_get_contents($existingPath));
        self::assertFileDoesNotExist($this->projectPath('src/Plugins/Cache/plugin.php'));
        self::assertFileDoesNotExist($this->projectPath('tests/Plugins/Cache/CachePluginTest.php'));
    }

    /**
     * @return iterable<string, array{0: list<string>}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [[]];
        yield 'extra token' => [['Cache', 'Extra']];
        yield 'lowercase first' => [['cache']];
        yield 'digit leading' => [['2Cache']];
        yield 'whitespace' => [["Ca che"]];
        yield 'slash' => [['../Cache']];
        yield 'backslash' => [['Vendor\\Cache']];
        yield 'dot' => [['Cache.Plugin']];
        yield 'shell metacharacter' => [['Cache&&echo']];
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
