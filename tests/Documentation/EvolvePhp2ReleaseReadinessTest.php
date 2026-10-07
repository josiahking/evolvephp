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
                array('name' => 'evolvephp/database-contracts', 'directory' => 'packages/database-contracts'),
                array('name' => 'evolvephp/database-pdo', 'directory' => 'packages/database-pdo'),
                array('name' => 'evolvephp/cache-memory', 'directory' => 'packages/cache-memory'),
                array('name' => 'evolvephp/session-contracts', 'directory' => 'packages/session-contracts'),
                array('name' => 'evolvephp/lock-contracts', 'directory' => 'packages/lock-contracts'),
                array('name' => 'evolvephp/queue-contracts', 'directory' => 'packages/queue-contracts'),
                array('name' => 'evolvephp/queue-memory', 'directory' => 'packages/queue-memory'),
                array('name' => 'evolvephp/storage-contracts', 'directory' => 'packages/storage-contracts'),
                array('name' => 'evolvephp/storage-local', 'directory' => 'packages/storage-local'),
                array('name' => 'evolvephp/secret-contracts', 'directory' => 'packages/secret-contracts'),
                array('name' => 'evolvephp/bridge-contracts', 'directory' => 'packages/bridge-contracts'),
                array('name' => 'evolvephp/core', 'directory' => 'packages/core'),
                array('name' => 'evolvephp/job', 'directory' => 'packages/job'),
                array('name' => 'evolvephp/scheduler', 'directory' => 'packages/scheduler'),
                array('name' => 'evolvephp/migration', 'directory' => 'packages/migration'),
                array('name' => 'evolvephp/view', 'directory' => 'packages/view'),
                array('name' => 'evolvephp/view-twig', 'directory' => 'packages/view-twig'),
                array('name' => 'evolvephp/view-blade', 'directory' => 'packages/view-blade'),
                array('name' => 'evolvephp/i18n', 'directory' => 'packages/i18n'),
                array('name' => 'evolvephp/module', 'directory' => 'packages/module'),
                array('name' => 'evolvephp/plugin', 'directory' => 'packages/plugin'),
                array('name' => 'evolvephp/http', 'directory' => 'packages/http'),
                array('name' => 'evolvephp/insight', 'directory' => 'packages/insight'),
                array('name' => 'evolvephp/http-client', 'directory' => 'packages/http-client'),
                array('name' => 'evolvephp/observe', 'directory' => 'packages/observe'),
                array('name' => 'evolvephp/bridge-psr', 'directory' => 'packages/bridge-psr'),
                array('name' => 'evolvephp/bridge-laravel', 'directory' => 'packages/bridge-laravel'),
                array('name' => 'evolvephp/bridge-symfony', 'directory' => 'packages/bridge-symfony'),
                array('name' => 'evolvephp/bridge-remote', 'directory' => 'packages/bridge-remote'),
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
            if ($package['name'] === 'evolvephp/observe') {
                $this->assertStringNotContainsString('`evolvephp/queue-memory`', $content);
            }
            $this->assertStringContainsString('BSD-3-Clause', $content);
            $this->assertStringContainsString('`LICENSE.md`', $content);
            $this->assertDoesNotMatchPattern('/composer require/i', $content);
            $this->assertDoesNotMatchPattern('/github\.com\/josiahking\/evolvephp[-\/](?:bridge-contracts|bridge-psr|bridge-remote|bridge-symfony|contracts|core|dev-tools|http|insight|lock-contracts|module|plugin|queue-contracts|queue-memory|secret-contracts|session-contracts|storage-contracts|storage-local|testing)/i', $content);
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
                '* text=auto eol=lf',
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

        $contentWithoutPackageName = str_replace(array('secret-contracts', 'secret\\\\contracts'), '', strtolower($content));

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
            $this->assertStringNotContainsString($forbidden, $contentWithoutPackageName);
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
            '/thirty-two packages.*mapped explicitly|mapped explicitly.*thirty-two packages|map contains thirty-two packages/i',
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
                'name' => 'evolvephp/database-contracts',
                'directory' => 'packages/database-contracts',
                'human' => 'EvolvePHP Database Contracts',
                'responsibility' => 'Portable SQL database statement and transaction contracts for EvolvePHP 2.',
                'dependencies' => '`evolvephp/contracts`',
            ),
            array(
                'name' => 'evolvephp/database-pdo',
                'directory' => 'packages/database-pdo',
                'human' => 'EvolvePHP Database PDO',
                'responsibility' => 'PDO database adapter for EvolvePHP 2 database contracts.',
                'dependencies' => '`evolvephp/contracts`, `evolvephp/database-contracts`',
            ),
            array(
                'name' => 'evolvephp/cache-memory',
                'directory' => 'packages/cache-memory',
                'human' => 'EvolvePHP Cache Memory',
                'responsibility' => 'In-memory PSR-16 cache implementation for EvolvePHP 2.',
                'dependencies' => '`psr/simple-cache`, `psr/clock`',
            ),
            array(
                'name' => 'evolvephp/session-contracts',
                'directory' => 'packages/session-contracts',
                'human' => 'EvolvePHP Session Contracts',
                'responsibility' => 'Runtime-neutral session contracts for EvolvePHP 2.',
                'dependencies' => '`evolvephp/contracts`',
            ),
            array(
                'name' => 'evolvephp/lock-contracts',
                'directory' => 'packages/lock-contracts',
                'human' => 'EvolvePHP Lock Contracts',
                'responsibility' => 'Runtime-neutral lock and lease contracts for EvolvePHP 2.',
                'dependencies' => '`evolvephp/contracts`',
            ),
            array(
                'name' => 'evolvephp/queue-contracts',
                'directory' => 'packages/queue-contracts',
                'human' => 'EvolvePHP Queue Contracts',
                'responsibility' => 'Runtime-neutral queue transport contracts for EvolvePHP 2.',
                'dependencies' => '`evolvephp/contracts`',
            ),
            array(
                'name' => 'evolvephp/queue-memory',
                'directory' => 'packages/queue-memory',
                'human' => 'EvolvePHP Queue Memory',
                'responsibility' => 'Object-local in-memory queue adapter for EvolvePHP 2.',
                'dependencies' => '`evolvephp/queue-contracts`',
            ),
            array(
                'name' => 'evolvephp/storage-contracts',
                'directory' => 'packages/storage-contracts',
                'human' => 'EvolvePHP Storage Contracts',
                'responsibility' => 'Vendor-neutral object storage contracts for EvolvePHP 2.',
                'dependencies' => '`evolvephp/contracts`',
            ),
            array(
                'name' => 'evolvephp/secret-contracts',
                'directory' => 'packages/secret-contracts',
                'human' => 'EvolvePHP Secret Contracts',
                'responsibility' => 'Vendor-neutral secret resolution contracts for EvolvePHP 2.',
                'dependencies' => '`evolvephp/contracts`',
            ),
            array(
                'name' => 'evolvephp/bridge-contracts',
                'directory' => 'packages/bridge-contracts',
                'human' => 'EvolvePHP Bridge Contracts',
                'responsibility' => 'Generic transport-neutral Bridge contracts for EvolvePHP 2.',
                'dependencies' => '`evolvephp/contracts`',
            ),
            array(
                'name' => 'evolvephp/core',
                'directory' => 'packages/core',
                'human' => 'EvolvePHP Core',
                'responsibility' => 'Application kernel and runtime-neutral orchestration for EvolvePHP 2.',
                'dependencies' => '`evolvephp/contracts`',
            ),
            array(
                'name' => 'evolvephp/job',
                'directory' => 'packages/job',
                'human' => 'EvolvePHP Job',
                'responsibility' => 'One-shot queue job execution runtime for EvolvePHP 2.',
                'dependencies' => '`evolvephp/core` and `evolvephp/queue-contracts`',
            ),
            array(
                'name' => 'evolvephp/scheduler',
                'directory' => 'packages/scheduler',
                'human' => 'EvolvePHP Scheduler',
                'responsibility' => 'One-tick scheduling runtime for EvolvePHP 2.',
                'dependencies' => '`evolvephp/core`, `evolvephp/lock-contracts`, `evolvephp/queue-contracts`, `psr/clock` and `dragonmantank/cron-expression`',
            ),
            array(
                'name' => 'evolvephp/migration',
                'directory' => 'packages/migration',
                'human' => 'EvolvePHP Migration',
                'responsibility' => 'Explicit one-shot migration runtime for EvolvePHP 2.',
                'dependencies' => '`evolvephp/contracts`, `evolvephp/core`, `evolvephp/database-contracts` and `evolvephp/lock-contracts`',
            ),
            array(
                'name' => 'evolvephp/view',
                'directory' => 'packages/view',
                'human' => 'EvolvePHP View',
                'responsibility' => 'Experimental server-rendered view contracts and trusted native PHP renderer for EvolvePHP 2.',
                'dependencies' => 'only PHP `^8.4`',
            ),
            array(
                'name' => 'evolvephp/view-twig',
                'directory' => 'packages/view-twig',
                'human' => 'EvolvePHP View Twig',
                'responsibility' => 'Optional Twig view renderer adapter for EvolvePHP 2.',
                'dependencies' => '`evolvephp/view` and `twig/twig`',
            ),
            array(
                'name' => 'evolvephp/view-blade',
                'directory' => 'packages/view-blade',
                'human' => 'EvolvePHP View Blade',
                'responsibility' => 'Optional Blade view renderer adapter for EvolvePHP 2.',
                'dependencies' => '`evolvephp/view` and the standalone Illuminate',
            ),
            array(
                'name' => 'evolvephp/i18n',
                'directory' => 'packages/i18n',
                'human' => 'EvolvePHP I18n',
                'responsibility' => 'Explicit internationalization and localization foundation for EvolvePHP 2.',
                'dependencies' => '`evolvephp/core`',
            ),
            array(
                'name' => 'evolvephp/insight',
                'directory' => 'packages/insight',
                'human' => 'EvolvePHP Insight',
                'responsibility' => 'Local diagnostic capture, persistence, query and access-policy foundation for EvolvePHP 2.',
                'dependencies' => '`evolvephp/core`',
            ),
            array(
                'name' => 'evolvephp/observe',
                'directory' => 'packages/observe',
                'human' => 'EvolvePHP Observe',
                'responsibility' => 'OpenTelemetry composition, execution, HTTP and infrastructure tracing, metrics, log correlation and bounded export-processing foundation for EvolvePHP 2.',
                'dependencies' => '`evolvephp/core`, `evolvephp/database-contracts`, `evolvephp/http`, `evolvephp/http-client`, `evolvephp/job`, `evolvephp/queue-contracts`, `evolvephp/storage-contracts`, `open-telemetry/api`, `open-telemetry/sem-conv`, `psr/http-client`, `psr/http-message`, `psr/http-server-handler`, `psr/http-server-middleware` and `psr/simple-cache`; optional SDK resource, sampler, export-processing, reader and lifecycle integration is supported when applications install `open-telemetry/sdk`.',
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
                'name' => 'evolvephp/http-client',
                'directory' => 'packages/http-client',
                'human' => 'EvolvePHP HTTP Client',
                'responsibility' => 'PSR-18 outbound HTTP client composition foundation for EvolvePHP 2.',
                'dependencies' => '`psr/http-client` and `psr/http-message`',
            ),
            array(
                'name' => 'evolvephp/bridge-psr',
                'directory' => 'packages/bridge-psr',
                'human' => 'EvolvePHP Bridge PSR',
                'responsibility' => 'Same-process PSR HTTP Bridge adapter foundation for EvolvePHP 2.',
                'dependencies' => '`evolvephp/bridge-contracts`, `evolvephp/core`, `evolvephp/http` and `psr/http-message`',
            ),
            array(
                'name' => 'evolvephp/bridge-laravel',
                'directory' => 'packages/bridge-laravel',
                'human' => 'EvolvePHP Bridge Laravel',
                'responsibility' => 'Laravel host Bridge adapter for embedded EvolvePHP 2 delegation.',
                'dependencies' => '`evolvephp/bridge-contracts`, `evolvephp/bridge-psr`, `illuminate/contracts`, `illuminate/http`, `psr/http-factory` and `psr/http-message`',
            ),
            array(
                'name' => 'evolvephp/bridge-symfony',
                'directory' => 'packages/bridge-symfony',
                'human' => 'EvolvePHP Bridge Symfony',
                'responsibility' => 'Symfony host Bridge adapter for embedded EvolvePHP 2 delegation.',
                'dependencies' => '`evolvephp/bridge-contracts`, `evolvephp/bridge-psr`, `psr/http-factory`, `psr/http-message`, `symfony/http-foundation` and `symfony/security-core`',
            ),
            array(
                'name' => 'evolvephp/bridge-remote',
                'directory' => 'packages/bridge-remote',
                'human' => 'EvolvePHP Bridge Remote',
                'responsibility' => 'Remote HTTP JSON Bridge protocol, PSR-18 host client and PSR-15 server endpoint for EvolvePHP 2.',
                'dependencies' => '`evolvephp/bridge-contracts`, `evolvephp/bridge-psr`, `psr/http-client`, `psr/http-message`, `psr/http-factory` and `psr/http-server-handler`',
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
