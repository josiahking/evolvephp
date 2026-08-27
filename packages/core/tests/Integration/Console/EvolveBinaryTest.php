<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Integration\Console;

use PHPUnit\Framework\TestCase;

final class EvolveBinaryTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        $this->temporaryDirectories = [];
    }

    public function testDoctorCommandRunsDefaultPhpVersionAndProjectComposerExtensionChecks(): void
    {
        $result = $this->runEvolve(['doctor'], dirname(__DIR__, 5));

        self::assertSame(0, $result->exitCode);
        self::assertStringContainsString('[PASS] runtime.php.version:', $result->stdout);
        self::assertStringContainsString('[PASS] project.composer.extensions:', $result->stdout);
        $phpVersionPosition = strpos($result->stdout, '[PASS] runtime.php.version:');
        $composerExtensionsPosition = strpos($result->stdout, '[PASS] project.composer.extensions:');
        self::assertNotFalse($phpVersionPosition);
        self::assertNotFalse($composerExtensionsPosition);
        self::assertLessThan($composerExtensionsPosition, $phpVersionPosition);
        self::assertSame('', $result->stderr);
    }

    public function testDoctorCommandReportsMissingProjectComposerManifestAsDiagnosticFailure(): void
    {
        $result = $this->runEvolve(['doctor'], $this->makeTemporaryDirectory());

        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('[PASS] runtime.php.version:', $result->stdout);
        self::assertStringContainsString('[FAIL] project.composer.extensions:', $result->stdout);
        self::assertSame('', $result->stderr);
    }

    public function testDoctorCommandRejectsUnsupportedArguments(): void
    {
        $result = $this->runEvolve(['doctor', '--json']);

        self::assertSame(2, $result->exitCode);
        self::assertStringNotContainsString('[PASS] runtime.php.version:', $result->stdout);
        self::assertStringContainsString('The doctor command does not accept arguments or options.', $result->stderr);
    }

    public function testMissingCommandReturnsUsageError(): void
    {
        $result = $this->runEvolve(['missing']);

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('Command "missing" was not found.', $result->stderr);
    }

    public function testNoCommandReturnsUsageError(): void
    {
        $result = $this->runEvolve([]);

        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('No command was specified.', $result->stderr);
    }

    /**
     * @param list<string> $arguments
     */
    private function runEvolve(array $arguments, ?string $workingDirectory = null): BinaryResult
    {
        $binary = dirname(__DIR__, 3) . '/bin/evolve';
        $command = [PHP_BINARY, $binary, ...$arguments];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory);

        self::assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return new BinaryResult(
            proc_close($process),
            $stdout,
            $stderr,
        );
    }

    private function makeTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evolve-binary-' . bin2hex(random_bytes(6));

        mkdir($directory);

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}

final readonly class BinaryResult
{
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
    ) {}
}
