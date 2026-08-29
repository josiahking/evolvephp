<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2ApplicationSkeletonTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testApplicationSkeletonHasAcceptedProjectManifest(): void
    {
        $manifest = $this->readJsonFile('skeleton/composer.json');

        $this->assertSame('evolvephp/skeleton', $manifest['name']);
        $this->assertSame('project', $manifest['type']);
        $this->assertSame('BSD-3-Clause', $manifest['license']);
        $this->assertSame('^8.4', $manifest['require']['php']);
        $this->assertSame('^2.0', $manifest['require']['evolvephp/contracts']);
        $this->assertSame('^2.0', $manifest['require']['evolvephp/core']);
        $this->assertSame('^2.0', $manifest['require']['evolvephp/http']);
        $this->assertSame('^2.0', $manifest['require']['evolvephp/module']);
        $this->assertSame('^2.0', $manifest['require']['evolvephp/plugin']);
        $this->assertArrayNotHasKey('evolvephp/testing', $manifest['require']);
        $this->assertArrayNotHasKey('evolvephp/dev-tools', $manifest['require']);
        $this->assertSame('^2.0', $manifest['require-dev']['evolvephp/dev-tools']);
        $this->assertSame('^2.0', $manifest['require-dev']['evolvephp/testing']);
        $this->assertSame('^13.2', $manifest['require-dev']['phpunit/phpunit']);
        $this->assertSame(array('App\\' => 'src/'), $manifest['autoload']['psr-4']);
        $this->assertSame(array('Tests\\' => 'tests/'), $manifest['autoload-dev']['psr-4']);
        $this->assertSame('phpunit --configuration phpunit.xml.dist', $manifest['scripts']['test']);
        $this->assertSame('alpha', $manifest['minimum-stability']);
        $this->assertTrue($manifest['prefer-stable']);
        $this->assertArrayNotHasKey('version', $manifest);
        $this->assertArrayNotHasKey('repositories', $manifest);
    }

    public function testApplicationSkeletonContainsOnlyTheAcceptedInitialLayout(): void
    {
        foreach (array(
            'skeleton/composer.json',
            'skeleton/README.md',
            'skeleton/bin/evolve',
            'skeleton/bootstrap/console.php',
            'skeleton/config/commands.php',
            'skeleton/config/routes.php',
            'skeleton/phpunit.xml.dist',
            'skeleton/src/.gitkeep',
            'skeleton/tests/.gitkeep',
        ) as $path) {
            $this->assertFileExists($this->path($path), $path . ' must exist.');
        }

        $this->assertSame(
            array(
                'README.md',
                'bin/evolve',
                'bootstrap/console.php',
                'composer.json',
                'config/commands.php',
                'config/routes.php',
                'phpunit.xml.dist',
                'src/.gitkeep',
                'tests/.gitkeep',
            ),
            $this->filesUnder('skeleton'),
        );
    }

    public function testApplicationSkeletonConsoleCompositionIsExplicit(): void
    {
        $bin = $this->readProjectFile('skeleton/bin/evolve');
        $bootstrap = $this->readProjectFile('skeleton/bootstrap/console.php');
        $commands = $this->readProjectFile('skeleton/config/commands.php');
        $routes = $this->readProjectFile('skeleton/config/routes.php');

        $this->assertStringContainsString("require dirname(__DIR__) . '/vendor/autoload.php';", $bin);
        $this->assertStringContainsString("require dirname(__DIR__) . '/bootstrap/console.php';", $bin);
        $this->assertStringContainsString('new StreamCommandOutput(STDOUT, STDERR)', $bin);
        $this->assertStringContainsString('$application->run(array_slice($argv, 1), $output)', $bin);

        foreach (array(
            'ServiceRegistry',
            'ExecutionOrchestrator',
            'CommandRegistry',
            'CommandRunner',
            'CliApplication',
        ) as $needle) {
            $this->assertStringContainsString($needle, $bootstrap);
        }

        $this->assertStringContainsString('DoctorCommand', $commands);
        $this->assertStringContainsString('DoctorRunner', $commands);
        $this->assertStringContainsString('PhpVersionCheck', $commands);
        $this->assertStringContainsString('ComposerRequiredExtensionsCheck', $commands);
        $this->assertStringContainsString('__DIR__ . \'/../composer.json\'', $commands);
        $this->assertStringContainsString('RouteListCommand', $commands);
        $this->assertStringContainsString('ModuleNewCommand', $commands);
        $this->assertStringContainsString('PluginNewCommand', $commands);
        $this->assertStringContainsString('class_exists(ModuleNewCommand::class)', $commands);
        $this->assertStringContainsString('class_exists(PluginNewCommand::class)', $commands);
        $this->assertStringContainsString("require __DIR__ . '/routes.php'", $commands);
        $this->assertDoesNotMatchRegularExpression('/EnvironmentVariablesCheck|WritablePathsCheck|dotenv|service locator|global command registry/i', $commands);

        $this->assertStringContainsString('return new RouteCollection([]);', $routes);
        $this->assertDoesNotMatchRegularExpression('/scan|glob|attribute|discover|filesystem|implicit/i', $routes);
    }

    public function testCoreRuntimeCliCompositionApisArePublicExperimental(): void
    {
        foreach (array(
            'packages/core/src/Console/Runtime/CliApplication.php',
            'packages/core/src/Console/Runtime/StreamCommandOutput.php',
        ) as $path) {
            $content = $this->readProjectFile($path);

            $this->assertStringContainsString('@experimental', $content, $path . ' must be public experimental.');
            $this->assertStringNotContainsString('@internal', $content, $path . ' must not remain internal.');
        }
    }

    public function testSkeletonIsExcludedFromFrameworkReleaseAndDeptracBoundaries(): void
    {
        $map = $this->readJsonFile('release-packages.json');
        $packageNames = array_column($map['packages'], 'name');
        $packageDirectories = array_column($map['packages'], 'directory');
        $deptrac = $this->readProjectFile('deptrac.php');
        $coreManifest = $this->readJsonFile('packages/core/composer.json');

        $this->assertCount(7, $map['packages']);
        $this->assertNotContains('evolvephp/skeleton', $packageNames);
        $this->assertNotContains('skeleton', $packageDirectories);
        $this->assertStringNotContainsString('skeleton', $deptrac);
        $this->assertArrayNotHasKey('evolvephp/http', $coreManifest['require']);
    }

    public function testSkeletonReadmeDocumentsAcceptedBoundariesWithoutPublicationClaims(): void
    {
        $readme = $this->readProjectFile('skeleton/README.md');

        foreach (array(
            'evolvephp/skeleton',
            'end-user application template',
            'App\\',
            'src/',
            'explicit',
            'RouteCollection',
            'No routes are configured.',
            'route:list',
            'doctor',
            'CliApplication',
            'StreamCommandOutput',
            'public experimental',
            'Core remains independent of HTTP',
            'composer release:skeleton:validate',
            'Packagist',
            '6.4E',
            '6.4F',
            'module:new',
            'plugin:new',
            'composer test',
        ) as $needle) {
            $this->assertStringContainsString($needle, $readme);
        }

        $this->assertDoesNotMatchRegularExpression('/composer create-project evolvephp\/skeleton/i', $readme);
        $this->assertDoesNotMatchRegularExpression('/automatic (?:route|command) discovery|autoloaded routes/i', $readme);
    }

    /**
     * @return list<string>
     */
    private function filesUnder(string $directory): array
    {
        $root = $this->path($directory);
        $this->assertDirectoryExists($root);

        $files = array();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }

        sort($files);

        return $files;
    }

    private function readProjectFile(string $path): string
    {
        $fullPath = $this->path($path);
        $this->assertFileExists($fullPath, $path . ' must exist.');

        $content = file_get_contents($fullPath);

        $this->assertIsString($content, $path . ' must be readable.');

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        $decoded = json_decode($this->readProjectFile($path), true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), $path . ' must contain valid JSON.');
        $this->assertIsArray($decoded, $path . ' must decode to an array.');

        return $decoded;
    }

    private function path(string $path): string
    {
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
