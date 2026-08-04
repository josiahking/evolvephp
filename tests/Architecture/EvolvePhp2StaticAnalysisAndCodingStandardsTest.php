<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2StaticAnalysisAndCodingStandardsTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testWorkspaceOwnsExactApprovedQualityDevelopmentDependencies(): void
    {
        $manifest = $this->readJsonFile('workspace/composer.json');

        $expectedRequireDev = array(
            'evolvephp/testing' => '^2.0@dev',
            'friendsofphp/php-cs-fixer' => '^3.95',
            'phpstan/phpstan' => '^2.2',
            'phpstan/phpstan-phpunit' => '^2.0',
            'phpunit/phpunit' => '^13.2',
        );

        $actualRequireDev = $manifest['require-dev'];

        ksort($expectedRequireDev);
        ksort($actualRequireDev);

        $this->assertSame($expectedRequireDev, $actualRequireDev);

        foreach ($this->qualityPackages() as $package) {
            $this->assertArrayNotHasKey($package, $manifest['require'], $package . ' must be development-only.');
        }
    }

    public function testRootAndPackageManifestsDoNotOwnQualityTooling(): void
    {
        $rootManifest = $this->readJsonFile('composer.json');

        foreach ($this->forbiddenQualityPackages() as $package) {
            $this->assertArrayNotHasKey($package, $rootManifest['require']);
            $this->assertArrayNotHasKey($package, $rootManifest['require-dev']);
        }

        foreach ($this->packageManifests() as $path) {
            $manifest = $this->readJsonFile($path);

            foreach ($this->forbiddenQualityPackages() as $package) {
                $this->assertArrayNotHasKey($package, $manifest['require'], $path . ' must not require ' . $package . '.');

                if (isset($manifest['require-dev'])) {
                    $this->assertArrayNotHasKey($package, $manifest['require-dev'], $path . ' must not require-dev ' . $package . '.');
                }
            }
        }
    }

    public function testWorkspaceScriptsDeclareExactQualityAndPhpUnitCommandMap(): void
    {
        $manifest = $this->readJsonFile('workspace/composer.json');

        $expectedScripts = array(
            'analyse' => '@php vendor/bin/phpstan analyse --configuration phpstan.neon.dist --no-progress',
            'quality' => array(
                '@analyse',
                '@style:check',
                '@test',
            ),
            'style:check' => '@php vendor/bin/php-cs-fixer check --config=.php-cs-fixer.dist.php --diff --verbose',
            'style:fix' => '@php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --diff --verbose',
            'test' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist',
            'test:contracts' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite contracts',
            'test:core' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite core',
            'test:http' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite http',
            'test:module' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite module',
            'test:plugin' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite plugin',
            'test:testing' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite testing',
        );

        $actualScripts = $manifest['scripts'];

        ksort($expectedScripts);
        ksort($actualScripts);

        $this->assertSame($expectedScripts, $actualScripts);
        $this->assertNotContains('@style:fix', $actualScripts['quality'], 'quality must remain non-mutating.');

        foreach (array('analyse', 'style:check', 'style:fix', 'test', 'test:contracts', 'test:core', 'test:http', 'test:module', 'test:plugin', 'test:testing') as $scriptName) {
            $this->assertStringStartsWith('@php ', $actualScripts[$scriptName], $scriptName . ' must use Composer @php.');
        }
    }

    public function testConfigurationFilesAreOwnedByWorkspaceAndNoTrackedAlternativesExist(): void
    {
        $this->assertFileExists($this->projectPath('workspace/phpstan.neon.dist'));
        $this->assertFileExists($this->projectPath('workspace/.php-cs-fixer.dist.php'));

        $trackedFiles = $this->trackedFiles();
        $forbidden = array(
            'phpstan.neon',
            'phpstan-baseline.neon',
            'psalm.xml',
            'psalm-baseline.xml',
            'phpcs.xml',
            'phpcs.xml.dist',
            'ecs.php',
            '.php-cs-fixer.php',
        );

        foreach ($trackedFiles as $file) {
            $normalized = str_replace('\\', '/', $file);

            foreach ($forbidden as $forbiddenFile) {
                $this->assertNotSame($forbiddenFile, basename($normalized), $normalized . ' must not be tracked.');
            }
        }
    }

    public function testPhpStanConfigurationDeclaresApprovedPolicy(): void
    {
        $content = $this->readProjectFile('workspace/phpstan.neon.dist');

        $this->assertStringContainsString('- vendor/phpstan/phpstan-phpunit/extension.neon', $content);
        $this->assertStringNotContainsString('vendor/phpstan/phpstan-phpunit/rules.neon', $content);
        $this->assertMatchesPattern('/level:\s*6/', $content);
        $this->assertMatchesPattern('/tmpDir:\s*\.phpstan-cache/', $content);
        $this->assertMatchesPattern('/reportIgnoresWithoutComments:\s*true/', $content);

        foreach ($this->packageAnalysisPaths() as $path) {
            $this->assertStringContainsString('- ' . $path, $content);
        }

        foreach (array('bootstrapFiles', 'ignoreErrors', 'baseline', 'config.platform.php') as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }
    }

    public function testPhpCsFixerConfigurationDeclaresApprovedPolicy(): void
    {
        $content = $this->readProjectFile('workspace/.php-cs-fixer.dist.php');

        $this->assertStringContainsString('declare(strict_types=1);', $content);
        $this->assertStringContainsString("'@PER-CS3x0' => true", $content);
        $this->assertStringNotContainsString("'@PER-CS' => true", $content);
        $this->assertStringNotContainsString("'@auto'", $content);
        $this->assertStringNotContainsString("'@PhpCsFixer'", $content);
        $this->assertStringNotContainsString("'@Symfony'", $content);
        $this->assertStringContainsString("'ordered_imports' =>", $content);
        $this->assertStringContainsString("'imports_order' => ['class', 'function', 'const']", $content);
        $this->assertStringContainsString("'sort_algorithm' => 'alpha'", $content);
        $this->assertStringContainsString("'no_unused_imports' => true", $content);
        $this->assertStringContainsString('->setRiskyAllowed(false)', $content);
        $this->assertStringContainsString('->setUsingCache(true)', $content);
        $this->assertStringContainsString("->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')", $content);
        $this->assertStringContainsString("->setIndent('    ')", $content);
        $this->assertStringContainsString('->setLineEnding("\n")', $content);
        $this->assertStringNotContainsString("'declare_strict_types'", $content);

        foreach ($this->packageAnalysisPaths() as $path) {
            $this->assertStringContainsString("'" . $path . "'", $content);
        }

        foreach (array("'../components'", "'../configs'", "'../core'", "'../docs'", "'../helpers'", "'../tests'", "'vendor'") as $forbiddenPath) {
            $this->assertStringNotContainsString($forbiddenPath, $content);
        }
    }

    public function testEveryTrackedPackagePhpFileUsesStrictTypesAndSourcesRemainSkeletons(): void
    {
        foreach ($this->trackedFiles() as $file) {
            $normalized = str_replace('\\', '/', $file);

            if (!$this->isTrackedPackagePhpFile($normalized)) {
                continue;
            }

            $content = $this->readProjectFile($normalized);

            $this->assertStringContainsString('declare(strict_types=1);', $content, $normalized . ' must declare strict types.');
        }

        foreach ($this->packageSourceDirectories() as $directory) {
            $phpFiles = $this->phpFilesUnder($this->projectPath($directory));

            $this->assertSame(array(), $phpFiles, $directory . ' must remain free from runtime implementation PHP files in Phase 2.4.');
        }
    }

    public function testGitignoreDeclaresQualityCacheAndLocalOverridePolicyWithoutBaselines(): void
    {
        $content = $this->readProjectFile('.gitignore');

        foreach (array(
            '/workspace/.phpstan-cache/',
            '/workspace/.php-cs-fixer.cache',
            '/workspace/phpstan.neon',
            '/workspace/.php-cs-fixer.php',
        ) as $ignoredPath) {
            $this->assertStringContainsString($ignoredPath, $content);
        }

        $this->assertStringContainsString('/workspace/vendor/', $content);
        $this->assertStringContainsString('!workspace/composer.lock', $content);
        $this->assertStringNotContainsString('phpstan-baseline', $content);
        $this->assertStringNotContainsString('psalm-baseline', $content);
    }

    private function qualityPackages()
    {
        return array(
            'friendsofphp/php-cs-fixer',
            'phpstan/phpstan',
            'phpstan/phpstan-phpunit',
        );
    }

    private function forbiddenQualityPackages()
    {
        return array(
            'friendsofphp/php-cs-fixer',
            'phpstan/phpstan',
            'phpstan/phpstan-phpunit',
            'vimeo/psalm',
            'squizlabs/php_codesniffer',
            'symplify/easy-coding-standard',
            'phpstan/extension-installer',
            'phpstan/phpstan-strict-rules',
            'qossmic/deptrac',
            'rector/rector',
            'infection/infection',
        );
    }

    private function packageAnalysisPaths()
    {
        $paths = array();

        foreach (array('contracts', 'core', 'http', 'module', 'plugin', 'testing') as $package) {
            $paths[] = '../packages/' . $package . '/src';
            $paths[] = '../packages/' . $package . '/tests';
        }

        return $paths;
    }

    private function packageManifests()
    {
        return array(
            'packages/contracts/composer.json',
            'packages/core/composer.json',
            'packages/http/composer.json',
            'packages/module/composer.json',
            'packages/plugin/composer.json',
            'packages/testing/composer.json',
        );
    }

    private function packageSourceDirectories()
    {
        return array(
            'packages/contracts/src',
            'packages/core/src',
            'packages/http/src',
            'packages/module/src',
            'packages/plugin/src',
            'packages/testing/src',
        );
    }

    private function isTrackedPackagePhpFile($path)
    {
        return preg_match('#^packages/[^/]+/(src|tests)/.*\.php$#', $path) === 1;
    }

    private function trackedFiles()
    {
        $output = array();
        $exitCode = 0;

        exec('git ls-files', $output, $exitCode);

        $this->assertSame(0, $exitCode, 'git ls-files should succeed.');

        return $output;
    }

    private function phpFilesUnder($directory)
    {
        $files = array();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
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
}
