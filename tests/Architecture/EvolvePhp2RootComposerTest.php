<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2RootComposerTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testRootComposerManifestIsTheEvolvePhp2DevelopmentManifest(): void
    {
        $manifest = $this->readJsonFile('composer.json');

        $this->assertSame('evolvephp/evolvephp', $manifest['name']);
        $this->assertSame('project', $manifest['type']);
        $this->assertSame('BSD-3-Clause', $manifest['license']);
        $this->assertSame('^8.4', $manifest['require']['php']);
        $this->assertSame(
            'A modernization-first PHP framework for building modular applications and evolving existing PHP systems without a full rewrite.',
            $manifest['description']
        );
        $this->assertSame('https://github.com/josiahking/evolvephp', $manifest['homepage']);
    }

    public function testRootRepositoryMapsAllLocalPackagesToExplicitDevelopmentVersions(): void
    {
        $manifest = $this->readJsonFile('composer.json');

        $this->assertArrayHasKey('repositories', $manifest);
        $this->assertCount(1, $manifest['repositories']);

        $repository = $manifest['repositories'][0];

        $this->assertSame('path', $repository['type']);
        $this->assertSame('packages/*', $repository['url']);
        $this->assertArrayHasKey('options', $repository);
        $this->assertArrayHasKey('versions', $repository['options']);
        $this->assertSame('config', $repository['options']['reference']);
        $this->assertArrayNotHasKey('symlink', $repository['options']);

        $versions = $repository['options']['versions'];

        $this->assertSame($this->allPackages(), array_keys($versions));

        foreach ($versions as $package => $version) {
            $this->assertSame('2.0.x-dev', $version, $package . ' should use the explicit root development version.');
        }
    }

    public function testRootRequiresOnlyAcceptedProductionAndDevelopmentDependencySets(): void
    {
        $manifest = $this->readJsonFile('composer.json');

        $expectedRequire = array(
            'php' => '^8.4',
            'evolvephp/contracts' => '^2.0@dev',
            'evolvephp/core' => '^2.0@dev',
            'evolvephp/http' => '^2.0@dev',
            'evolvephp/module' => '^2.0@dev',
            'evolvephp/plugin' => '^2.0@dev',
        );

        $expectedRequireDev = array(
            'deptrac/deptrac' => '^4.7',
            'evolvephp/dev-tools' => '^2.0@dev',
            'evolvephp/testing' => '^2.0@dev',
            'friendsofphp/php-cs-fixer' => '^3.95',
            'phpstan/phpstan' => '^2.2',
            'phpstan/phpstan-phpunit' => '^2.0',
            'phpunit/phpunit' => '^13.2',
        );

        $actualRequire = $manifest['require'];
        $actualRequireDev = $manifest['require-dev'];

        ksort($expectedRequire);
        ksort($actualRequire);
        ksort($expectedRequireDev);
        ksort($actualRequireDev);

        $this->assertSame($expectedRequire, $actualRequire);
        $this->assertSame($expectedRequireDev, $actualRequireDev);
    }

    public function testRootManifestAvoidsLegacyAndDeferredPolicyFields(): void
    {
        $manifest = $this->readJsonFile('composer.json');

        foreach (array('version', 'minimum-stability', 'prefer-stable', 'autoload', 'autoload-dev') as $field) {
            $this->assertArrayNotHasKey($field, $manifest, 'composer.json must not contain ' . $field . '.');
        }

        $this->assertTrue($manifest['config']['sort-packages']);
        $this->assertFalse(isset($manifest['config']['platform']), 'composer.json must not emulate Composer platform configuration.');

        foreach ($this->legacyDependencies() as $package) {
            $this->assertPackageAbsentFromManifest($package, $manifest, 'composer.json');
        }
    }

    public function testRootComposerManifestDeclaresOnlyApprovedDevelopmentScripts(): void
    {
        $manifest = $this->readJsonFile('composer.json');
        $expectedScripts = array(
            'architecture' => '@php vendor/bin/deptrac analyse --config-file=deptrac.php --no-progress --report-uncovered --fail-on-uncovered',
            'analyse' => '@php vendor/bin/phpstan analyse --configuration phpstan.neon.dist --no-progress',
            'licenses:check' => '@php tools/check-licenses.php',
            'quality' => array('@architecture', '@analyse', '@style:check', '@test'),
            'release:consumer:validate' => '@php tools/validate-prerelease-consumers.php',
            'release:skeleton:validate' => '@php tools/validate-skeleton-project.php',
            'release:split:validate' => '@php tools/validate-package-splits.php',
            'release:validate' => '@php tools/validate-release-packages.php',
            'security:audit' => '@composer audit --locked --abandoned=fail',
            'style:check' => '@php vendor/bin/php-cs-fixer check --config=.php-cs-fixer.dist.php --diff --verbose',
            'style:fix' => '@php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --diff --verbose',
            'supply-chain' => array('@security:audit', '@licenses:check'),
            'test' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist',
            'test:contracts' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite contracts',
            'test:core' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite core',
            'test:dev-tools' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite dev-tools',
            'test:http' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite http',
            'test:module' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite module',
            'test:plugin' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite plugin',
            'test:testing' => '@php vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite testing',
        );

        $this->assertSame($expectedScripts, $manifest['scripts']);
        $this->assertNotContains('@style:fix', $manifest['scripts']['quality']);
        $this->assertNotContains('@release:validate', $manifest['scripts']['quality']);
        $this->assertNotContains('@release:validate', $manifest['scripts']['supply-chain']);
    }

    public function testRootLockQualityReleaseAndDevelopmentFilesExist(): void
    {
        foreach (array(
            'composer.lock',
            'phpunit.xml.dist',
            'phpstan.neon.dist',
            '.php-cs-fixer.dist.php',
            'deptrac.php',
            'release-packages.json',
            'DEVELOPMENT.md',
            'tools/check-licenses.php',
            'tools/release-validation-common.php',
            'tools/validate-package-splits.php',
            'tools/validate-prerelease-consumers.php',
            'tools/validate-release-packages.php',
            'tools/validate-skeleton-project.php',
        ) as $path) {
            $this->assertFileExists($this->projectPath($path), $path . ' should exist at repository root after cutover.');
        }
    }

    public function testWorkspaceDirectoryAndLegacyRuntimePathsAreRetiredFromEvolvePhp2Tree(): void
    {
        $this->assertDirectoryDoesNotExist($this->projectPath('workspace'));

        foreach ($this->legacyRuntimePaths() as $path) {
            $fullPath = $this->projectPath($path);

            if (is_dir($fullPath)) {
                $this->assertDirectoryDoesNotExist($fullPath, $path . ' must be removed from the 2.x working tree.');
            } else {
                $this->assertFileDoesNotExist($fullPath, $path . ' must be removed from the 2.x working tree.');
            }
        }
    }

    public function testPreservationHistoryAndGovernanceFilesRemainPresent(): void
    {
        foreach (array(
            'docs/history',
            'AGENTS.md',
            'SECURITY.md',
            'SUPPORT.md',
            'LICENSE.md',
            'COPYRIGHT.md',
        ) as $path) {
            $this->assertTrue(file_exists($this->projectPath($path)), $path . ' must remain after the cutover.');
        }
    }

    public function testReleaseMapContainsOnlyTheSevenReleasePackages(): void
    {
        $map = $this->readJsonFile('release-packages.json');

        $this->assertSame(1, $map['version']);
        $this->assertSame(
            array(
                array('name' => 'evolvephp/contracts', 'directory' => 'packages/contracts'),
                array('name' => 'evolvephp/core', 'directory' => 'packages/core'),
                array('name' => 'evolvephp/module', 'directory' => 'packages/module'),
                array('name' => 'evolvephp/plugin', 'directory' => 'packages/plugin'),
                array('name' => 'evolvephp/http', 'directory' => 'packages/http'),
                array('name' => 'evolvephp/testing', 'directory' => 'packages/testing'),
                array('name' => 'evolvephp/dev-tools', 'directory' => 'packages/dev-tools'),
            ),
            $map['packages']
        );

        $this->assertNotContains('evolvephp/evolvephp', array_column($map['packages'], 'name'));
    }

    public function testRootProjectIsDocumentedAsDevelopmentRootAndNotSeventhReleasePackage(): void
    {
        $readme = $this->readProjectFile('README.md');
        $development = $this->readProjectFile('DEVELOPMENT.md');

        foreach (array($readme, $development) as $content) {
            $this->assertMatchesPattern('/repository root.*EvolvePHP 2.*development root|EvolvePHP 2.*development root.*repository root/is', $content);
            $this->assertMatchesPattern('/not a release package|not.*publishable.*framework package/is', $content);
        }
    }

    private function allPackages()
    {
        return array(
            'evolvephp/contracts',
            'evolvephp/core',
            'evolvephp/dev-tools',
            'evolvephp/http',
            'evolvephp/module',
            'evolvephp/plugin',
            'evolvephp/testing',
        );
    }

    private function legacyDependencies()
    {
        return array(
            'karelwintersky/monolog-pdo-handler',
            'monolog/monolog',
            'apache/log4php',
            'whichbrowser/parser',
            'phpunit/phpunit:8',
        );
    }

    private function legacyRuntimePaths()
    {
        return array(
            '.htaccess',
            'components',
            'configs',
            'core',
            'helpers',
            'index.php',
            'logs',
            'public',
            'route.php',
            'tasks',
            'tests/index.html',
        );
    }

    private function assertPackageAbsentFromManifest($package, array $manifest, $path): void
    {
        foreach (array('require', 'require-dev') as $section) {
            if (!isset($manifest[$section])) {
                continue;
            }

            if (strpos($package, ':') !== false) {
                list($packageName, $constraint) = explode(':', $package, 2);
                $this->assertFalse(
                    isset($manifest[$section][$packageName]) && $manifest[$section][$packageName] === $constraint,
                    $path . ' must not declare legacy dependency ' . $package . '.'
                );

                continue;
            }

            $this->assertArrayNotHasKey($package, $manifest[$section], $path . ' must not declare legacy dependency ' . $package . '.');
        }
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
