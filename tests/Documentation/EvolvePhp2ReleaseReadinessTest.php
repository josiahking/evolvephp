<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2ReleaseReadinessTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testReleasePackageMapDefinesCanonicalDependencyCompatibleOrder(): void
    {
        $map = $this->readJsonFile('release-packages.json');

        $this->assertSame(array('version', 'packages'), array_keys($map));
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

        foreach ($map['packages'] as $package) {
            $this->assertSame(array('name', 'directory'), array_keys($package));
            $this->assertDoesNotMatchPattern('/^(?:[A-Za-z]:)?[\/\\\\]/', $package['directory']);
            $this->assertStringNotContainsString('..', $package['directory']);

            foreach (array('url', 'repository', 'packagist', 'tag', 'version', 'branch', 'token', 'secret', 'password', 'status') as $forbidden) {
                $this->assertArrayNotHasKey($forbidden, $package);
            }
        }
    }

    public function testPackageReadmesDocumentPublicationStatusWithoutInventingRemoteRepositories(): void
    {
        foreach ($this->packages() as $package) {
            $content = $this->readProjectFile($package['directory'] . '/README.md');

            $this->assertStringContainsString('# ' . $package['human'], $content);
            $this->assertStringContainsString('`' . $package['name'] . '`', $content);
            $this->assertStringContainsString($package['responsibility'], $content);
            $this->assertStringContainsString('PHP `^8.4`', $content);
            $this->assertMatchesPattern('/EvolvePHP 2 is pre-release/i', $content);
            $this->assertMatchesPattern('/not yet independently published/i', $content);
            $this->assertMatchesPattern('/canonical source.*EvolvePHP monorepo/i', $content);
            $this->assertStringContainsString('https://github.com/josiahking/evolvephp', $content);
            $this->assertStringContainsString($package['dependencies'], $content);
            $this->assertStringContainsString('BSD-3-Clause', $content);
            $this->assertStringContainsString('`LICENSE.md`', $content);
            $this->assertDoesNotMatchPattern('/composer require/i', $content);
            $this->assertDoesNotMatchPattern('/github\.com\/josiahking\/evolvephp[-\/](?:contracts|core|dev-tools|http|module|plugin|testing)/i', $content);
        }
    }

    public function testPackageLicenceFilesMatchRootLicenceByteForByte(): void
    {
        $rootLicence = $this->readProjectFile('LICENSE.md');

        foreach ($this->packages() as $package) {
            $this->assertSame(
                $rootLicence,
                $this->readProjectFile($package['directory'] . '/LICENSE.md'),
                $package['directory'] . '/LICENSE.md should match root LICENSE.md byte-for-byte.'
            );
        }
    }

    public function testPackageLicenceWhitespaceExceptionIsNarrowAndDeliberate(): void
    {
        $attributes = $this->readProjectFile('.gitattributes');

        $this->assertSame(
            array(
                '# Package licences intentionally mirror root LICENSE.md byte-for-byte.',
                'packages/*/LICENSE.md -whitespace',
            ),
            $this->nonEmptyLines($attributes)
        );
    }

    public function testReleaseValidatorIsReadOnlyNetworkFreeAndPortable(): void
    {
        $content = $this->readProjectFile('tools/validate-release-packages.php');

        $this->assertMatchesPattern('/^<\?php\s+declare\(strict_types=1\);/s', $content);
        $this->assertStringContainsString('--root=', $content);
        $this->assertStringContainsString('release-packages.json', $content);
        $this->assertStringContainsString('DIRECTORY_SEPARATOR', $content);

        foreach (array(
            'curl_',
            'file_get_contents(\'http',
            'file_get_contents("http',
            'github api',
            'packagist',
            'token',
            'secret',
            'password',
            'git push',
            'git tag',
            'gh release',
            'file_put_contents',
            'unlink',
            'rename',
            'mkdir',
            'exec(',
            'shell_exec',
            'system(',
            'passthru(',
        ) as $forbidden) {
            $this->assertStringNotContainsString($forbidden, strtolower($content));
        }
    }

    public function testWorkspaceComposerExposesReleaseValidationWithoutChangingQualityOrSupplyChain(): void
    {
        $manifest = $this->readJsonFile('composer.json');
        $scripts = $manifest['scripts'];

        $this->assertArrayHasKey('release:validate', $scripts);
        $this->assertSame('@php tools/validate-release-packages.php', $scripts['release:validate']);
        $this->assertSame(array('@architecture', '@analyse', '@style:check', '@test'), $scripts['quality']);
        $this->assertSame(array('@security:audit', '@licenses:check'), $scripts['supply-chain']);
        $this->assertNotContains('@release:validate', $scripts['quality']);
        $this->assertNotContains('@release:validate', $scripts['supply-chain']);
    }

    public function testWorkspaceReadmeDocumentsReleaseValidationBoundaries(): void
    {
        $content = $this->readProjectFile('DEVELOPMENT.md');

        foreach (array(
            '/## Release Validation/',
            '/composer release:validate/',
            '/deterministic\/offline|offline.*deterministic/i',
            '/seven packages.*mapped explicitly|mapped explicitly.*seven packages|map contains seven packages/i',
            '/dependency-compatible/i',
            '/package-local README/i',
            '/package-local.*licen[cs]es/i',
            '/identical to root `LICENSE\.md`/i',
            '/no package is being published/i',
            '/no remote repositories are contacted/i',
            '/no tags\/releases are created/i',
            '/package Composer manifests remain authoritative/i',
            '/distinct from `quality`/i',
            '/distinct from.*`supply-chain`/i',
            '/package splitting.*release:split:validate|release:split:validate.*package splitting/i',
            '/remote synchronization.*Packagist.*deferred/i',
            '/prerelease consumer stability.*offline consumer matrix|offline consumer matrix.*prerelease consumer stability/i',
            '/RFC 0003 remains authoritative/i',
        ) as $pattern) {
            $this->assertMatchesPattern($pattern, $content);
        }
    }

    public function testChangelogRecordsPhase210AReleaseReadinessFoundation(): void
    {
        $content = $this->readProjectFile('CHANGELOG.md');

        $this->assertMatchesPattern('/Phase 2\.10A/i', $content);
        $this->assertMatchesPattern('/deterministic package release validation/i', $content);
        $this->assertMatchesPattern('/explicit release package map/i', $content);
        $this->assertMatchesPattern('/package-local README.*licen[cs]e/i', $content);
        $this->assertMatchesPattern('/no remote publication or splitting/i', $content);
    }

    private function packages()
    {
        return array(
            array(
                'name' => 'evolvephp/contracts',
                'directory' => 'packages/contracts',
                'human' => 'EvolvePHP Contracts',
                'responsibility' => 'Foundational public contracts for EvolvePHP 2.',
                'dependencies' => 'None.',
            ),
            array(
                'name' => 'evolvephp/core',
                'directory' => 'packages/core',
                'human' => 'EvolvePHP Core',
                'responsibility' => 'Application kernel and runtime-neutral orchestration for EvolvePHP 2.',
                'dependencies' => '`evolvephp/contracts`',
            ),
            array(
                'name' => 'evolvephp/dev-tools',
                'directory' => 'packages/dev-tools',
                'human' => 'EvolvePHP DevTools',
                'responsibility' => 'Development-time generators and tooling for EvolvePHP 2 applications.',
                'dependencies' => '`evolvephp/contracts`, `evolvephp/core`, `evolvephp/module`, `evolvephp/plugin`',
            ),
            array(
                'name' => 'evolvephp/module',
                'directory' => 'packages/module',
                'human' => 'EvolvePHP Module',
                'responsibility' => 'Application module SDK and lifecycle support for EvolvePHP 2.',
                'dependencies' => '`evolvephp/contracts`',
            ),
            array(
                'name' => 'evolvephp/plugin',
                'directory' => 'packages/plugin',
                'human' => 'EvolvePHP Plugin',
                'responsibility' => 'Framework plugin SDK and lifecycle support for EvolvePHP 2.',
                'dependencies' => '`evolvephp/contracts`',
            ),
            array(
                'name' => 'evolvephp/http',
                'directory' => 'packages/http',
                'human' => 'EvolvePHP HTTP',
                'responsibility' => 'HTTP lifecycle, routing and middleware foundations for EvolvePHP 2.',
                'dependencies' => '`evolvephp/contracts`, `evolvephp/core`',
            ),
            array(
                'name' => 'evolvephp/testing',
                'directory' => 'packages/testing',
                'human' => 'EvolvePHP Testing',
                'responsibility' => 'Testing utilities for EvolvePHP 2 packages and applications.',
                'dependencies' => '`evolvephp/contracts`, `evolvephp/core`, `evolvephp/http`, `evolvephp/module`, `evolvephp/plugin`',
            ),
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

        $content = file_get_contents($fullPath);
        $this->assertNotFalse($content, $path . ' should be readable.');

        return $content;
    }

    /**
     * @return list<string>
     */
    private function nonEmptyLines(string $content): array
    {
        return array_values(array_filter(
            preg_split('/\r?\n/', $content) ?: array(),
            static fn (string $line): bool => $line !== ''
        ));
    }

    private function readJsonFile($path)
    {
        $content = $this->readProjectFile($path);
        $decoded = json_decode($content, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), $path . ' should contain valid JSON: ' . json_last_error_msg());
        $this->assertIsArray($decoded);

        return $decoded;
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
