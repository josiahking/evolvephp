<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2WorkspaceComposerTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testWorkspaceComposerManifestExistsAndContainsValidJson(): void
    {
        $manifest = $this->readJsonFile('workspace/composer.json');

        $this->assertSame('evolvephp/workspace', $manifest['name']);
        $this->assertSame('project', $manifest['type']);
        $this->assertSame('BSD-3-Clause', $manifest['license']);
    }

    public function testWorkspaceRepositoryMapsAllLocalPackagesToExplicitWorkspaceVersion(): void
    {
        $manifest = $this->readJsonFile('workspace/composer.json');

        $this->assertArrayHasKey('repositories', $manifest);
        $this->assertCount(1, $manifest['repositories']);

        $repository = $manifest['repositories'][0];

        $this->assertSame('path', $repository['type']);
        $this->assertSame('../packages/*', $repository['url']);
        $this->assertArrayHasKey('options', $repository);
        $this->assertArrayHasKey('versions', $repository['options']);
        $this->assertSame('config', $repository['options']['reference']);
        $this->assertArrayNotHasKey('symlink', $repository['options']);

        $versions = $repository['options']['versions'];

        $this->assertSame($this->allPackages(), array_keys($versions));

        foreach ($versions as $package => $version) {
            $this->assertSame('2.0.x-dev', $version, $package . ' should use the explicit workspace version.');
        }
    }

    public function testWorkspaceRequiresProductionPackagesAndKeepsTestingInRequireDev(): void
    {
        $manifest = $this->readJsonFile('workspace/composer.json');

        $expectedRequire = array(
            'php' => '^8.4',
            'evolvephp/contracts' => '^2.0@dev',
            'evolvephp/core' => '^2.0@dev',
            'evolvephp/http' => '^2.0@dev',
            'evolvephp/module' => '^2.0@dev',
            'evolvephp/plugin' => '^2.0@dev',
        );

        $expectedRequireDev = array(
            'evolvephp/testing' => '^2.0@dev',
        );

        $actualRequire = $manifest['require'];
        $actualRequireDev = $manifest['require-dev'];

        ksort($expectedRequire);
        ksort($expectedRequireDev);
        ksort($actualRequire);
        ksort($actualRequireDev);

        $this->assertSame($expectedRequire, $actualRequire);
        $this->assertSame($expectedRequireDev, $actualRequireDev);
        $this->assertArrayNotHasKey('evolvephp/testing', $actualRequire);

        foreach ($this->productionPackages() as $package) {
            $this->assertArrayNotHasKey($package, $actualRequireDev);
        }
    }

    public function testWorkspaceComposerManifestAvoidsDeferredPolicyFieldsAndExternalDependencies(): void
    {
        $manifest = $this->readJsonFile('workspace/composer.json');

        foreach (array('version', 'minimum-stability', 'prefer-stable', 'autoload', 'autoload-dev', 'scripts') as $field) {
            $this->assertArrayNotHasKey($field, $manifest, 'workspace/composer.json must not contain ' . $field . '.');
        }

        $this->assertTrue($manifest['config']['sort-packages']);
        $this->assertFalse(isset($manifest['config']['platform']), 'workspace/composer.json must not emulate Composer platform configuration.');

        foreach (array('require', 'require-dev') as $section) {
            foreach (array_keys($manifest[$section]) as $dependency) {
                $this->assertTrue(
                    $dependency === 'php' || in_array($dependency, $this->allPackages(), true),
                    $dependency . ' must not be added as an external workspace dependency.'
                );
            }
        }
    }

    public function testWorkspaceReadmeDocumentsBoundarySolverOnlyVerificationAndLockfilePolicy(): void
    {
        $content = $this->readProjectFile('workspace/README.md');

        $this->assertMatchesPattern('/dedicated EvolvePHP 2 Composer development root/i', $content);
        $this->assertMatchesPattern('/legacy root Composer harness remains separate/i', $content);
        $this->assertMatchesPattern('/not a publishable framework package/i', $content);
        $this->assertMatchesPattern('/\.\.\/packages\/\*/i', $content);
        $this->assertMatchesPattern('/2\.0\.x-dev/i', $content);
        $this->assertMatchesPattern('/\^2\.0@dev/i', $content);
        $this->assertMatchesPattern('/task-branch version ambiguity/i', $content);
        $this->assertMatchesPattern('/minimum-stability/i', $content);
        $this->assertMatchesPattern('/resolve all six packages as `?2\.0\.x-dev`?/i', $content);
        $this->assertMatchesPattern('/production packages.*`require`/is', $content);
        $this->assertMatchesPattern('/Testing.*`require-dev`/is', $content);
        $this->assertMatchesPattern('/composer --working-dir=workspace validate --strict/i', $content);
        $this->assertMatchesPattern('/--dry-run\s+\\\\?\s*--no-install\s+\\\\?\s*--no-interaction\s+\\\\?\s*--ignore-platform-req=php/is', $content);
        $this->assertMatchesPattern('/dependency graph only/i', $content);
        $this->assertMatchesPattern('/does not install packages/i', $content);
        $this->assertMatchesPattern('/does not create the lockfile/i', $content);
        $this->assertMatchesPattern('/does not prove PHP 8\.4 or 8\.5 runtime compatibility/i', $content);
        $this->assertMatchesPattern('/must not be used for production installation or compatibility claims/i', $content);
        $this->assertMatchesPattern('/workspace\/composer\.lock.*intended to be committed/is', $content);
        $this->assertMatchesPattern('/intentionally absent in Phase 2\.2/i', $content);
        $this->assertMatchesPattern('/generated under real PHP 8\.4 execution/i', $content);
        $this->assertMatchesPattern('/through Composer, never manually/i', $content);
    }

    public function testGitignoreKeepsWorkspaceLockfileTrackableAndIgnoresWorkspaceVendor(): void
    {
        $content = $this->readProjectFile('.gitignore');

        $generalLockfilePosition = strpos($content, 'composer.lock');
        $workspaceLockfilePosition = strpos($content, '!workspace/composer.lock');

        $this->assertNotFalse($generalLockfilePosition, '.gitignore should keep the general composer.lock rule.');
        $this->assertNotFalse($workspaceLockfilePosition, '.gitignore should explicitly allow workspace/composer.lock.');
        $this->assertGreaterThan($generalLockfilePosition, $workspaceLockfilePosition, 'workspace/composer.lock should be allowed after the general lockfile ignore rule.');
        $this->assertMatchesPattern('/^\/workspace\/vendor\/$/m', $content);
    }

    public function testPackageReadmeReferencesTheDedicatedWorkspace(): void
    {
        $content = $this->readProjectFile('packages/README.md');

        $this->assertMatchesPattern('/Phase 2\.2 now provides the dedicated Composer workspace/i', $content);
        $this->assertMatchesPattern('/workspace\/README\.md/i', $content);
        $this->assertMatchesPattern('/skeletons? only|no runtime implementation/i', $content);
        $this->assertMatchesPattern('/not been installed or runtime-tested/i', $content);
        $this->assertMatchesPattern('/Real PHP 8\.4 and PHP 8\.5 CI evidence is required before compatibility is claimed/i', $content);
    }

    public function testChangelogRecordsPhase22WorkspaceComposerConfiguration(): void
    {
        $content = $this->readProjectFile('CHANGELOG.md');

        $this->assertMatchesPattern('/##\s+\[?Unreleased\]?/i', $content);
        $this->assertMatchesPattern('/Phase 2\.2/i', $content);
        $this->assertMatchesPattern('/dedicated EvolvePHP 2 Composer workspace/i', $content);
        $this->assertMatchesPattern('/path-repository integration/i', $content);
        $this->assertMatchesPattern('/2\.0\.x-dev package version mapping/i', $content);
    }

    private function allPackages()
    {
        return array(
            'evolvephp/contracts',
            'evolvephp/core',
            'evolvephp/http',
            'evolvephp/module',
            'evolvephp/plugin',
            'evolvephp/testing',
        );
    }

    private function productionPackages()
    {
        return array(
            'evolvephp/contracts',
            'evolvephp/core',
            'evolvephp/http',
            'evolvephp/module',
            'evolvephp/plugin',
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

    private function assertMatchesPattern($pattern, $content)
    {
        $this->assertSame(1, preg_match($pattern, $content), 'Failed asserting that content matches ' . $pattern);
    }
}
