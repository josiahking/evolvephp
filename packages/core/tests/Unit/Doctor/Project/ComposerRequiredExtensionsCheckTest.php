<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Doctor\Project;

use Evolve\Core\Doctor\DoctorStatus;
use Evolve\Core\Doctor\Project\ComposerRequiredExtensionsCheck;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ComposerRequiredExtensionsCheckTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            $this->removeDirectory($directory);
        }

        $this->temporaryDirectories = [];
    }

    public function testIdentifierIsProjectComposerExtensions(): void
    {
        self::assertSame('project.composer.extensions', (new ComposerRequiredExtensionsCheck($this->missingManifestPath()))->identifier());
    }

    public function testEmptyConstructorPathRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ComposerRequiredExtensionsCheck('');
    }

    public function testWhitespaceOnlyConstructorPathRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ComposerRequiredExtensionsCheck('   ');
    }

    public function testUriPathRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ComposerRequiredExtensionsCheck('https://example.com/composer.json');
    }

    public function testMissingManifestFails(): void
    {
        $path = $this->missingManifestPath();
        $finding = (new ComposerRequiredExtensionsCheck($path))->run();

        self::assertSame(DoctorStatus::Fail, $finding->status());
        self::assertSame('project.composer.extensions', $finding->identifier());
        self::assertSame(sprintf('Composer project manifest is unavailable at %s.', $path), $finding->message());
    }

    public function testInvalidJsonFails(): void
    {
        $path = $this->writeComposerJson('{');
        $finding = (new ComposerRequiredExtensionsCheck($path))->run();

        self::assertSame(DoctorStatus::Fail, $finding->status());
        self::assertSame(sprintf('Composer project manifest is not valid JSON at %s.', $path), $finding->message());
    }

    public function testJsonNullRootFails(): void
    {
        $path = $this->writeComposerJson('null');
        $finding = (new ComposerRequiredExtensionsCheck($path))->run();

        self::assertSame(DoctorStatus::Fail, $finding->status());
        self::assertSame(sprintf('Composer project manifest must contain a JSON object at %s.', $path), $finding->message());
    }

    public function testJsonScalarRootFails(): void
    {
        $path = $this->writeComposerJson('"composer"');
        $finding = (new ComposerRequiredExtensionsCheck($path))->run();

        self::assertSame(DoctorStatus::Fail, $finding->status());
        self::assertSame(sprintf('Composer project manifest must contain a JSON object at %s.', $path), $finding->message());
    }

    public function testJsonListRootFails(): void
    {
        $path = $this->writeComposerJson('[]');
        $finding = (new ComposerRequiredExtensionsCheck($path))->run();

        self::assertSame(DoctorStatus::Fail, $finding->status());
        self::assertSame(sprintf('Composer project manifest must contain a JSON object at %s.', $path), $finding->message());
    }

    public function testMissingRequireSectionPassesWithNoExtensionRequirements(): void
    {
        $path = $this->writeComposerJson('{"name":"example/project"}');
        $finding = (new ComposerRequiredExtensionsCheck($path, static fn(string $extension): bool => false))->run();

        self::assertSame(DoctorStatus::Pass, $finding->status());
        self::assertSame('Composer project declares no required PHP extensions.', $finding->message());
    }

    public function testEmptyRequireObjectPasses(): void
    {
        $path = $this->writeComposerJson('{"require":{}}');
        $finding = (new ComposerRequiredExtensionsCheck($path, static fn(string $extension): bool => false))->run();

        self::assertSame(DoctorStatus::Pass, $finding->status());
        self::assertSame('Composer project declares no required PHP extensions.', $finding->message());
    }

    public function testRequireListFails(): void
    {
        $path = $this->writeComposerJson('{"require":[]}');
        $finding = (new ComposerRequiredExtensionsCheck($path))->run();

        self::assertSame(DoctorStatus::Fail, $finding->status());
        self::assertSame(sprintf('Composer project runtime requirements must be a JSON object at %s.', $path), $finding->message());
    }

    public function testUnrelatedRuntimePackagesIgnored(): void
    {
        $path = $this->writeComposerJson('{"require":{"php":"^8.4","psr/container":"^2.0"}}');
        $finding = (new ComposerRequiredExtensionsCheck($path, static fn(string $extension): bool => false))->run();

        self::assertSame(DoctorStatus::Pass, $finding->status());
        self::assertSame('Composer project declares no required PHP extensions.', $finding->message());
    }

    public function testRequireDevExtensionRequirementsIgnored(): void
    {
        $path = $this->writeComposerJson('{"require-dev":{"ext-missing":"*"}}');
        $finding = (new ComposerRequiredExtensionsCheck($path, static fn(string $extension): bool => false))->run();

        self::assertSame(DoctorStatus::Pass, $finding->status());
        self::assertSame('Composer project declares no required PHP extensions.', $finding->message());
    }

    public function testOneRuntimeExtensionDeclarationIsDiscovered(): void
    {
        $path = $this->writeComposerJson('{"require":{"ext-json":"*"}}');
        $finding = (new ComposerRequiredExtensionsCheck($path, static fn(string $extension): bool => true))->run();

        self::assertSame(DoctorStatus::Pass, $finding->status());
        self::assertSame('All Composer-declared PHP extensions are loaded: json.', $finding->message());
    }

    public function testMultipleRuntimeExtensionsAreNormalizedAndSortedDeterministically(): void
    {
        $path = $this->writeComposerJson('{"require":{"ext-pdo":"*","ext-json":"*","ext-mbstring":"*"}}');
        $finding = (new ComposerRequiredExtensionsCheck($path, static fn(string $extension): bool => true))->run();

        self::assertSame(DoctorStatus::Pass, $finding->status());
        self::assertSame('All Composer-declared PHP extensions are loaded: json, mbstring, pdo.', $finding->message());
    }

    public function testUppercaseExtensionDeclarationNormalizesToLowercase(): void
    {
        $path = $this->writeComposerJson('{"require":{"EXT-JSON":"*"}}');
        $finding = (new ComposerRequiredExtensionsCheck($path, static fn(string $extension): bool => true))->run();

        self::assertSame(DoctorStatus::Pass, $finding->status());
        self::assertSame('All Composer-declared PHP extensions are loaded: json.', $finding->message());
    }

    public function testMalformedEmptyExtensionSuffixFails(): void
    {
        $path = $this->writeComposerJson('{"require":{"ext-":"*"}}');
        $finding = (new ComposerRequiredExtensionsCheck($path))->run();

        self::assertSame(DoctorStatus::Fail, $finding->status());
        self::assertSame(sprintf('Composer project PHP extension requirement "ext-" is malformed at %s.', $path), $finding->message());
    }

    public function testMalformedExtensionNameFails(): void
    {
        $path = $this->writeComposerJson('{"require":{"ext-foo/bar":"*"}}');
        $finding = (new ComposerRequiredExtensionsCheck($path))->run();

        self::assertSame(DoctorStatus::Fail, $finding->status());
        self::assertSame(
            sprintf(
                'Composer project PHP extension requirement "ext-foo/bar" is malformed at %s.',
                $path,
            ),
            $finding->message(),
        );
    }

    public function testNonStringExtensionConstraintFails(): void
    {
        $path = $this->writeComposerJson('{"require":{"ext-json":null}}');
        $finding = (new ComposerRequiredExtensionsCheck($path))->run();

        self::assertSame(DoctorStatus::Fail, $finding->status());
        self::assertSame(sprintf('Composer project PHP extension requirement "ext-json" must use a non-empty string constraint at %s.', $path), $finding->message());
    }

    public function testEmptyExtensionConstraintFails(): void
    {
        $path = $this->writeComposerJson('{"require":{"ext-json":""}}');
        $finding = (new ComposerRequiredExtensionsCheck($path))->run();

        self::assertSame(DoctorStatus::Fail, $finding->status());
        self::assertSame(sprintf('Composer project PHP extension requirement "ext-json" must use a non-empty string constraint at %s.', $path), $finding->message());
    }

    public function testNormalizedDuplicateExtensionDeclarationsFail(): void
    {
        $path = $this->writeComposerJson('{"require":{"ext-JSON":"*","ext-json":"*"}}');
        $finding = (new ComposerRequiredExtensionsCheck($path))->run();

        self::assertSame(DoctorStatus::Fail, $finding->status());
        self::assertSame(sprintf('Composer project PHP extension "json" is declared more than once after normalization at %s.', $path), $finding->message());
    }

    public function testAllLoadedPasses(): void
    {
        $path = $this->writeComposerJson('{"require":{"ext-json":"*","ext-mbstring":"^1"}}');
        $finding = (new ComposerRequiredExtensionsCheck($path, static fn(string $extension): bool => true))->run();

        self::assertSame(DoctorStatus::Pass, $finding->status());
        self::assertSame('All Composer-declared PHP extensions are loaded: json, mbstring.', $finding->message());
    }

    public function testOneMissingFails(): void
    {
        $path = $this->writeComposerJson('{"require":{"ext-json":"*","ext-mbstring":"*"}}');
        $finding = (new ComposerRequiredExtensionsCheck($path, static fn(string $extension): bool => $extension === 'json'))->run();

        self::assertSame(DoctorStatus::Fail, $finding->status());
        self::assertSame('Missing Composer-declared PHP extension: mbstring.', $finding->message());
    }

    public function testMultipleMissingFailWithDeterministicOrdering(): void
    {
        $path = $this->writeComposerJson('{"require":{"ext-pdo":"*","ext-json":"*","ext-mbstring":"*"}}');
        $finding = (new ComposerRequiredExtensionsCheck($path, static fn(string $extension): bool => $extension === 'json'))->run();

        self::assertSame(DoctorStatus::Fail, $finding->status());
        self::assertSame('Missing Composer-declared PHP extensions: mbstring, pdo.', $finding->message());
    }

    public function testInjectedLookupClosureReceivesCanonicalNormalizedNamesInSortedOrder(): void
    {
        $received = [];
        $path = $this->writeComposerJson('{"require":{"ext-pdo":"*","EXT-JSON":"*","ext-mbstring":"*"}}');

        (new ComposerRequiredExtensionsCheck(
            $path,
            function (string $extension) use (&$received): bool {
                $received[] = $extension;

                return true;
            },
        ))->run();

        self::assertSame(['json', 'mbstring', 'pdo'], $received);
    }

    public function testRemediationIdentifiesMissingExtension(): void
    {
        $path = $this->writeComposerJson('{"require":{"ext-json":"*","ext-mbstring":">=1"}}');
        $finding = (new ComposerRequiredExtensionsCheck($path, static fn(string $extension): bool => $extension === 'json'))->run();

        self::assertSame('Install or enable the missing PHP extension: mbstring.', $finding->remediation());
    }

    private function missingManifestPath(): string
    {
        return $this->makeTemporaryDirectory() . DIRECTORY_SEPARATOR . 'composer.json';
    }

    private function writeComposerJson(string $contents): string
    {
        $path = $this->missingManifestPath();

        file_put_contents($path, $contents);

        return $path;
    }

    private function makeTemporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evolve-composer-extensions-' . bin2hex(random_bytes(6));

        mkdir($directory);

        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());

                continue;
            }

            unlink($file->getPathname());
        }

        rmdir($directory);
    }
}
