<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2PackageSkeletonTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testExpectedPackageManifestsAndSourceDirectoriesExist(): void
    {
        foreach ($this->packages() as $package) {
            $this->assertFileExists(
                $this->projectPath($package['manifest']),
                $package['manifest'] . ' should exist.'
            );
            $this->assertTrue(
                is_dir($this->projectPath($package['src'])),
                $package['src'] . ' should exist as a source directory.'
            );
        }
    }

    public function testPackageManifestsDeclareAcceptedComposerMetadata(): void
    {
        foreach ($this->packages() as $package) {
            $manifest = $this->readJsonFile($package['manifest']);

            foreach (array('name', 'description', 'type', 'license', 'require', 'autoload') as $field) {
                $this->assertArrayHasKey($field, $manifest, $package['manifest'] . ' should contain ' . $field . '.');
            }

            $this->assertSame($package['name'], $manifest['name'], $package['manifest'] . ' should use the accepted package name.');
            $this->assertSame($package['description'], $manifest['description'], $package['manifest'] . ' should use the accepted description.');
            $this->assertSame('library', $manifest['type'], $package['manifest'] . ' should be a library package.');
            $this->assertSame('BSD-3-Clause', $manifest['license'], $package['manifest'] . ' should use the project licence.');
            $this->assertSame('^8.4', $manifest['require']['php'], $package['manifest'] . ' should require PHP ^8.4.');

            $this->assertArrayHasKey('psr-4', $manifest['autoload'], $package['manifest'] . ' should declare PSR-4 autoloading.');
            $this->assertSame(
                array($package['namespace'] => 'src/'),
                $manifest['autoload']['psr-4'],
                $package['manifest'] . ' should map the accepted namespace to src/.'
            );
        }
    }

    public function testPackageDependenciesFollowAcceptedInwardDirection(): void
    {
        foreach ($this->packages() as $package) {
            $manifest = $this->readJsonFile($package['manifest']);
            $expectedRequire = $package['require'];
            $actualRequire = $manifest['require'];

            ksort($expectedRequire);
            ksort($actualRequire);

            $this->assertSame(
                $expectedRequire,
                $actualRequire,
                $package['manifest'] . ' should declare only the accepted package dependencies.'
            );
        }
    }

    public function testProductionPackagesDoNotDependOnTestingAndGraphHasNoCycles(): void
    {
        $graph = array();

        foreach ($this->packages() as $package) {
            $manifest = $this->readJsonFile($package['manifest']);
            $dependencies = array();

            foreach ($manifest['require'] as $dependency => $constraint) {
                if (strpos($dependency, 'evolvephp/') === 0) {
                    $dependencies[] = $dependency;
                }
            }

            if ($package['name'] !== 'evolvephp/testing') {
                $this->assertNotContains(
                    'evolvephp/testing',
                    $dependencies,
                    $package['name'] . ' must not depend on evolvephp/testing.'
                );
            }

            $graph[$package['name']] = $dependencies;
        }

        $this->assertPackageGraphIsAcyclic($graph);
    }

    public function testPackageManifestsAvoidDeferredComposerPolicyFields(): void
    {
        $forbiddenFields = array('version', 'repositories', 'minimum-stability', 'prefer-stable');

        foreach ($this->packages() as $package) {
            $manifest = $this->readJsonFile($package['manifest']);

            foreach ($forbiddenFields as $field) {
                $this->assertArrayNotHasKey($field, $manifest, $package['manifest'] . ' must not contain ' . $field . '.');
            }

            $this->assertArrayNotHasKey('files', $manifest['autoload'], $package['manifest'] . ' must not use autoload.files.');
            $this->assertFalse(
                isset($manifest['config']['platform']['php']),
                $package['manifest'] . ' must not emulate Composer platform PHP.'
            );
        }
    }

    public function testPackageSourcesMatchPhase33RuntimeInventory(): void
    {
        foreach ($this->phase33SourceInventories() as $sourceDirectory => $expectedFiles) {
            $fullSourceDirectory = $this->projectPath($sourceDirectory);

            $this->assertTrue(is_dir($fullSourceDirectory), $sourceDirectory . ' should exist before source files are inspected.');

            $this->assertSame(
                $expectedFiles,
                $this->phpFilesUnderSource($fullSourceDirectory),
                $sourceDirectory . ' should contain exactly the approved Phase 3.3 PHP source inventory.'
            );
        }

        $this->assertFileDoesNotExist($this->projectPath('packages/contracts/src/.gitkeep'));
        $this->assertFileDoesNotExist($this->projectPath('packages/core/src/.gitkeep'));
        $this->assertFileExists($this->projectPath('packages/http/src/.gitkeep'));
        $this->assertFileExists($this->projectPath('packages/module/src/.gitkeep'));
        $this->assertFileExists($this->projectPath('packages/plugin/src/.gitkeep'));
        $this->assertFileExists($this->projectPath('packages/testing/src/.gitkeep'));
    }

    public function testPackageOverviewDocumentsSkeletonBoundariesAndCompatibilityLimits(): void
    {
        $content = $this->readProjectFile('packages/README.md');
        $uninstalledHistory = 'not been installed or ' . 'runtime-tested';
        $phpCompatibilityHistory = 'Real PHP 8.4 and PHP 8.5 CI evidence is required before ' . 'compatibility is claimed';
        $probeHistory = 'temporary forbidden-edge ' . 'probe';

        $this->assertMatchesPattern('/# EvolvePHP 2 Packages/i', $content);
        $this->assertMatchesPattern('/skeleton|foundation|boundar/i', $content);
        $this->assertMatchesPattern('/complete runtime (?:framework )?implementation is not yet present/i', $content);
        $this->assertMatchesPattern('/packages.*not yet published|not yet published.*packages/i', $content);
        $this->assertMatchesPattern('/PHP `?\^8\.4`?/i', $content);
        $this->assertMatchesPattern('/arrows.*dependency direction.*not lifecycle invocation/i', $content);
        $this->assertMatchesPattern('/no production dependency on Testing/i', $content);
        $this->assertMatchesPattern('/workspace\/README\.md/i', $content);
        $this->assertMatchesPattern('/setup.*testing.*quality|testing.*quality.*setup|quality.*setup.*testing/is', $content);

        foreach ($this->packages() as $package) {
            $this->assertStringContainsString($package['name'], $content);
            $this->assertStringContainsString($package['namespace'], $content);
        }

        $this->assertDoesNotMatchPattern('/Phase 2\.2 now provides/i', $content);
        $this->assertDoesNotMatchPattern('/Before Phase 2\.3/i', $content);
        $this->assertDoesNotMatchPattern('/' . preg_quote($uninstalledHistory, '/') . '/i', $content);
        $this->assertDoesNotMatchPattern('/' . preg_quote($phpCompatibilityHistory, '/') . '/i', $content);
        $this->assertDoesNotMatchPattern('/' . preg_quote($probeHistory, '/') . '/i', $content);
    }

    public function testCoreDeclaresPsrContainerInteroperabilityMetadata(): void
    {
        $coreManifest = $this->readJsonFile('packages/core/composer.json');

        $this->assertSame(
            '^1.1 || ^2.0',
            $coreManifest['require']['psr/container'] ?? null,
            'Core should require the approved PSR-11 container contract range.'
        );
        $this->assertSame(
            array('psr/container-implementation' => '1.0.0'),
            $coreManifest['provide'] ?? array(),
            'Core should advertise the approved PSR-11 implementation metadata.'
        );

        foreach ($this->packages() as $package) {
            if ($package['name'] === 'evolvephp/core') {
                continue;
            }

            $manifest = $this->readJsonFile($package['manifest']);

            $this->assertArrayNotHasKey(
                'psr/container',
                $manifest['require'],
                $package['manifest'] . ' must not require PSR-11 for Phase 3.3.'
            );
            $this->assertArrayNotHasKey(
                'provide',
                $manifest,
                $package['manifest'] . ' must not advertise PSR-11 implementation metadata.'
            );
        }
    }

    public function testChangelogRecordsPhase21PackageSkeleton(): void
    {
        $content = $this->readProjectFile('CHANGELOG.md');

        $this->assertMatchesPattern('/##\s+\[?Unreleased\]?/i', $content);
        $this->assertMatchesPattern('/Phase 2\.1/i', $content);
        $this->assertMatchesPattern('/initial EvolvePHP 2 package skeleton/i', $content);
    }

    private function packages()
    {
        return array(
            array(
                'manifest' => 'packages/contracts/composer.json',
                'src' => 'packages/contracts/src',
                'name' => 'evolvephp/contracts',
                'description' => 'Foundational public contracts for EvolvePHP 2.',
                'namespace' => 'Evolve\\Contracts\\',
                'require' => array('php' => '^8.4'),
            ),
            array(
                'manifest' => 'packages/core/composer.json',
                'src' => 'packages/core/src',
                'name' => 'evolvephp/core',
                'description' => 'Application kernel and runtime-neutral orchestration for EvolvePHP 2.',
                'namespace' => 'Evolve\\Core\\',
                'require' => array('php' => '^8.4', 'evolvephp/contracts' => '^2.0', 'psr/container' => '^1.1 || ^2.0'),
            ),
            array(
                'manifest' => 'packages/http/composer.json',
                'src' => 'packages/http/src',
                'name' => 'evolvephp/http',
                'description' => 'HTTP lifecycle, routing and middleware foundations for EvolvePHP 2.',
                'namespace' => 'Evolve\\Http\\',
                'require' => array('php' => '^8.4', 'evolvephp/contracts' => '^2.0', 'evolvephp/core' => '^2.0'),
            ),
            array(
                'manifest' => 'packages/module/composer.json',
                'src' => 'packages/module/src',
                'name' => 'evolvephp/module',
                'description' => 'Application module SDK and lifecycle support for EvolvePHP 2.',
                'namespace' => 'Evolve\\Module\\',
                'require' => array('php' => '^8.4', 'evolvephp/contracts' => '^2.0'),
            ),
            array(
                'manifest' => 'packages/plugin/composer.json',
                'src' => 'packages/plugin/src',
                'name' => 'evolvephp/plugin',
                'description' => 'Framework plugin SDK and lifecycle support for EvolvePHP 2.',
                'namespace' => 'Evolve\\Plugin\\',
                'require' => array('php' => '^8.4', 'evolvephp/contracts' => '^2.0'),
            ),
            array(
                'manifest' => 'packages/testing/composer.json',
                'src' => 'packages/testing/src',
                'name' => 'evolvephp/testing',
                'description' => 'Testing utilities for EvolvePHP 2 packages and applications.',
                'namespace' => 'Evolve\\Testing\\',
                'require' => array(
                    'php' => '^8.4',
                    'evolvephp/contracts' => '^2.0',
                    'evolvephp/core' => '^2.0',
                    'evolvephp/http' => '^2.0',
                    'evolvephp/module' => '^2.0',
                    'evolvephp/plugin' => '^2.0',
                ),
            ),
        );
    }

    private function phase33SourceInventories()
    {
        return array(
            'packages/contracts/src' => array(
                'Configuration/Configuration.php',
                'Configuration/ConfigurationValidator.php',
                'Exception/ConfigurationException.php',
                'Exception/EvolveException.php',
                'Exception/LifecycleException.php',
                'Lifecycle/ApplicationLifecycle.php',
            ),
            'packages/core/src' => array(
                'ApplicationKernel.php',
                'Configuration/ArrayConfiguration.php',
                'Container/ServiceContainer.php',
                'Container/ServiceDefinition.php',
                'Container/ServiceLifetime.php',
                'Container/ServiceRegistry.php',
                'Exception/ConfigurationValidationFailed.php',
                'Exception/InvalidConfiguration.php',
                'Exception/InvalidLifecycleTransition.php',
                'Exception/InvalidServiceDefinition.php',
                'Exception/ServiceNotFound.php',
                'Exception/ServiceRegistryFrozen.php',
                'Exception/ServiceResolutionFailed.php',
                'Lifecycle/ApplicationState.php',
            ),
            'packages/http/src' => array(),
            'packages/module/src' => array(),
            'packages/plugin/src' => array(),
            'packages/testing/src' => array(),
        );
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

    private function phpFilesUnderSource($directory)
    {
        $files = array();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $relativePath = substr($file->getPathname(), strlen($directory) + 1);
                $files[] = str_replace('\\', '/', $relativePath);
            }
        }

        sort($files);

        return $files;
    }

    private function assertPackageGraphIsAcyclic(array $graph): void
    {
        $visiting = array();
        $visited = array();

        foreach (array_keys($graph) as $packageName) {
            $this->visitPackage($packageName, $graph, $visiting, $visited, array());
        }
    }

    private function visitPackage($packageName, array $graph, array &$visiting, array &$visited, array $path): void
    {
        if (isset($visited[$packageName])) {
            return;
        }

        $this->assertArrayNotHasKey(
            $packageName,
            $visiting,
            'Package dependency graph must be acyclic; cycle detected: ' . implode(' -> ', array_merge($path, array($packageName)))
        );

        $visiting[$packageName] = true;
        $path[] = $packageName;

        foreach ($graph[$packageName] as $dependency) {
            if (isset($graph[$dependency])) {
                $this->visitPackage($dependency, $graph, $visiting, $visited, $path);
            }
        }

        unset($visiting[$packageName]);
        $visited[$packageName] = true;
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
