<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2PhpUnitFoundationTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testRootRequiresPhpUnitOnlyAsADevelopmentDependency(): void
    {
        $manifest = $this->readJsonFile('composer.json');

        $this->assertArrayHasKey('require-dev', $manifest);
        $this->assertArrayHasKey('evolvephp/testing', $manifest['require-dev']);
        $this->assertSame('^2.0@dev', $manifest['require-dev']['evolvephp/testing']);
        $this->assertArrayHasKey('phpunit/phpunit', $manifest['require-dev']);
        $this->assertSame('^13.2', $manifest['require-dev']['phpunit/phpunit']);
        $this->assertArrayNotHasKey('phpunit/phpunit', $manifest['require']);
    }

    public function testRootComposerScriptsRunTheApprovedPhpUnitSuites(): void
    {
        $manifest = $this->readJsonFile('composer.json');
        $expectedScripts = $this->workspaceScripts();

        $this->assertArrayHasKey('scripts', $manifest);

        $actualScripts = $manifest['scripts'];

        foreach ($expectedScripts as $name => $script) {
            $this->assertArrayHasKey($name, $actualScripts);
            $this->assertSame($script, $actualScripts[$name]);
            $this->assertStringContainsString('--configuration phpunit.xml.dist', $actualScripts[$name]);
        }
    }

    public function testRootPhpUnitConfigurationDefinesSixPackageSuites(): void
    {
        $path = $this->projectPath('phpunit.xml.dist');

        $this->assertFileExists($path);

        $document = new DOMDocument();
        $this->assertTrue($document->load($path), 'phpunit.xml.dist should be well formed XML.');

        $root = $document->documentElement;

        $this->assertSame('phpunit', $root->tagName);
        $this->assertSame('vendor/autoload.php', $root->getAttribute('bootstrap'));
        $this->assertSame('false', $root->getAttribute('cacheResult'));

        $xpath = new DOMXPath($document);
        $suiteNodes = $xpath->query('/phpunit/testsuites/testsuite');

        $this->assertNotFalse($suiteNodes);
        $this->assertSame(array_keys($this->packageSuites()), $this->nodeAttributeValues($suiteNodes, 'name'));

        foreach ($suiteNodes as $suiteNode) {
            $suiteName = $suiteNode->getAttribute('name');
            $directories = $xpath->query('directory', $suiteNode);

            $this->assertNotFalse($directories);
            $this->assertSame(1, $directories->length, $suiteName . ' should declare one test directory.');
            $this->assertSame($this->packageSuites()[$suiteName]['tests'], trim($directories->item(0)->textContent));
            $this->assertSame('Test.php', $directories->item(0)->getAttribute('suffix'));
        }
    }

    public function testEachPackageHasAManifestSmokeTest(): void
    {
        foreach ($this->packageSuites() as $suite) {
            $this->assertFileExists($this->projectPath($suite['smokeTest']));
            $this->assertNotSame(
                array(),
                $this->phpTestFilesUnder($this->projectPath(dirname($suite['smokeTest'], 2))),
                $suite['tests'] . ' should contain at least one test file.'
            );
        }
    }

    public function testRootLockfileRecordsLocalPackagesAndPhpUnit13(): void
    {
        $lock = $this->readJsonFile('composer.lock');

        $this->assertArrayHasKey('content-hash', $lock);
        $this->assertNotSame('', $lock['content-hash']);
        $this->assertSame('^8.4', $lock['platform']['php']);

        $packages = $this->lockedPackages($lock);

        foreach ($this->expectedLockedPackages() as $package) {
            $this->assertArrayHasKey($package, $packages, $package . ' should be locked.');
        }

        $this->assertSame(13, (int) strtok($packages['phpunit/phpunit'], '.'));
    }

    public function testDevelopmentGuideDocumentsPhpUnitFoundationPolicy(): void
    {
        $content = $this->readProjectFile('DEVELOPMENT.md');

        $this->assertMatchesPattern('/PHPUnit 13/i', $content);
        $this->assertMatchesPattern('/PHP 8\.4/i', $content);
        $this->assertMatchesPattern('/phpunit\.xml\.dist/i', $content);
        $this->assertMatchesPattern('/composer test/i', $content);
        $this->assertMatchesPattern('/test:contracts/i', $content);
        $this->assertMatchesPattern('/test:core/i', $content);
        $this->assertMatchesPattern('/test:http/i', $content);
        $this->assertMatchesPattern('/test:module/i', $content);
        $this->assertMatchesPattern('/test:plugin/i', $content);
        $this->assertMatchesPattern('/test:testing/i', $content);
        $this->assertMatchesPattern('/composer\.lock/i', $content);
        $this->assertMatchesPattern('/platform emulation/i', $content);
        $this->assertMatchesPattern('/PHP 8\.4.*baseline|baseline.*PHP 8\.4/i', $content);
        $this->assertMatchesPattern('/GitHub Actions.*root quality.*PHP 8\.4.*PHP 8\.5|root quality.*GitHub Actions.*PHP 8\.4.*PHP 8\.5|PHP 8\.4.*PHP 8\.5.*root quality.*GitHub Actions/i', $content);
        $this->assertMatchesPattern('/current.*(?:root quality|package foundation|tooling)|(?:root quality|package foundation|tooling).*current/i', $content);
        $this->assertDoesNotMatchPattern('/PHP 8\.5.*pending|pending.*PHP 8\.5|Phase 2\.6.*pending|pending.*Phase 2\.6/i', $content);
        $this->assertMatchesPattern('/legacy root suite/i', $content);
        $this->assertMatchesPattern('/EvolvePHP 2 root suite/i', $content);
    }

    public function testDevelopmentGuideOwnsDetailedPackageTestDocumentation(): void
    {
        $developmentGuide = $this->readProjectFile('DEVELOPMENT.md');
        $packagesReadme = $this->readProjectFile('packages/README.md');

        $this->assertMatchesPattern('/phpunit\.xml\.dist/i', $developmentGuide);
        $this->assertMatchesPattern('/PHPUnit 13.*root|root.*PHPUnit 13/i', $developmentGuide);

        foreach (array('test:contracts', 'test:core', 'test:http', 'test:module', 'test:plugin', 'test:testing') as $script) {
            $this->assertMatchesPattern('/' . preg_quote($script, '/') . '/i', $developmentGuide);
        }

        foreach ($this->packageSuites() as $suiteName => $suite) {
            $this->assertMatchesPattern('/`?' . preg_quote($suiteName, '/') . '`?.*' . preg_quote($suite['tests'], '/') . '/i', $developmentGuide);
        }

        $this->assertMatchesPattern('/DEVELOPMENT\.md/i', $packagesReadme);
        $this->assertMatchesPattern('/testing.*quality|quality.*testing/i', $packagesReadme);
        $this->assertDoesNotMatchPattern('/tests\/Unit\//i', $packagesReadme);
        $this->assertDoesNotMatchPattern('/test:contracts.*test:core.*test:http.*test:module.*test:plugin.*test:testing/is', $packagesReadme);
        $this->assertDoesNotMatchPattern('/phpunit\.xml\.dist.*PHPUnit|PHPUnit.*phpunit\.xml\.dist/is', $packagesReadme);
    }

    public function testChangelogRecordsPhase23PhpUnitFoundation(): void
    {
        $content = $this->readProjectFile('CHANGELOG.md');

        $this->assertMatchesPattern('/##\s+\[?Unreleased\]?/i', $content);
        $this->assertMatchesPattern('/Phase 2\.3/i', $content);
        $this->assertMatchesPattern('/PHPUnit 13/i', $content);
        $this->assertMatchesPattern('/six package test suites/i', $content);
        $this->assertMatchesPattern('/PHP 8\.4/i', $content);
    }

    private function packageSuites()
    {
        return array(
            'contracts' => array(
                'tests' => 'packages/contracts/tests',
                'smokeTest' => 'packages/contracts/tests/Unit/PackageManifestTest.php',
            ),
            'core' => array(
                'tests' => 'packages/core/tests',
                'smokeTest' => 'packages/core/tests/Unit/PackageManifestTest.php',
            ),
            'http' => array(
                'tests' => 'packages/http/tests',
                'smokeTest' => 'packages/http/tests/Unit/PackageManifestTest.php',
            ),
            'module' => array(
                'tests' => 'packages/module/tests',
                'smokeTest' => 'packages/module/tests/Unit/PackageManifestTest.php',
            ),
            'plugin' => array(
                'tests' => 'packages/plugin/tests',
                'smokeTest' => 'packages/plugin/tests/Unit/PackageManifestTest.php',
            ),
            'testing' => array(
                'tests' => 'packages/testing/tests',
                'smokeTest' => 'packages/testing/tests/Unit/PackageManifestTest.php',
            ),
        );
    }

    private function workspaceScripts()
    {
        return array(
            'test' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist',
            'test:contracts' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite contracts',
            'test:core' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite core',
            'test:http' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite http',
            'test:module' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite module',
            'test:plugin' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite plugin',
            'test:testing' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite testing',
        );
    }

    private function expectedLockedPackages()
    {
        return array(
            'evolvephp/contracts',
            'evolvephp/core',
            'evolvephp/http',
            'evolvephp/module',
            'evolvephp/plugin',
            'evolvephp/testing',
            'phpunit/phpunit',
        );
    }

    private function lockedPackages(array $lock)
    {
        $packages = array();

        foreach (array('packages', 'packages-dev') as $section) {
            foreach ($lock[$section] as $package) {
                $packages[$package['name']] = $package['version'];
            }
        }

        return $packages;
    }

    private function nodeAttributeValues(DOMNodeList $nodes, $attribute)
    {
        $values = array();

        foreach ($nodes as $node) {
            $values[] = $node->getAttribute($attribute);
        }

        return $values;
    }

    private function phpTestFilesUnder($directory)
    {
        if (!is_dir($directory)) {
            return array();
        }

        $files = array();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && substr($file->getFilename(), -8) === 'Test.php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function projectPath($path)
    {
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function readProjectFile($path)
    {
        $fullPath = $this->projectPath($path);
        $this->assertFileExists($fullPath, $path . ' should exist before it is read.');

        return file_get_contents($fullPath);
    }

    private function readJsonFile($path)
    {
        $content = $this->readProjectFile($path);
        $json = json_decode($content, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), $path . ' should contain valid JSON: ' . json_last_error_msg());
        $this->assertIsArray($json, $path . ' should decode to a JSON object.');

        return $json;
    }

    private function assertMatchesPattern($pattern, $content)
    {
        $this->assertSame(1, preg_match($pattern, $content), 'Failed asserting that content matches ' . $pattern);
    }

    private function assertDoesNotMatchPattern($pattern, $content)
    {
        $this->assertSame(0, preg_match($pattern, $content), 'Failed asserting that content does not match ' . $pattern);
    }
}
