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

    public function testPackageSourcesMatchAcceptedPackageSourceInventory(): void
    {
        foreach ($this->acceptedPackageSourceInventories() as $sourceDirectory => $expectedFiles) {
            $fullSourceDirectory = $this->projectPath($sourceDirectory);

            $this->assertTrue(is_dir($fullSourceDirectory), $sourceDirectory . ' should exist before source files are inspected.');

            $this->assertSame(
                $expectedFiles,
                $this->phpFilesUnderSource($fullSourceDirectory),
                $sourceDirectory . ' should contain exactly the approved package PHP source inventory.'
            );
        }

        $this->assertFileDoesNotExist($this->projectPath('packages/contracts/src/.gitkeep'));
        $this->assertFileDoesNotExist($this->projectPath('packages/core/src/.gitkeep'));
        $this->assertFileDoesNotExist($this->projectPath('packages/http/src/.gitkeep'));
        $this->assertFileDoesNotExist($this->projectPath('packages/module/src/.gitkeep'));
        $this->assertFileDoesNotExist($this->projectPath('packages/plugin/src/.gitkeep'));
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
        $this->assertMatchesPattern('/DEVELOPMENT\.md/i', $content);
        $this->assertMatchesPattern('/setup.*testing.*quality|testing.*quality.*setup|quality.*setup.*testing/is', $content);
        $this->assertMatchesPattern('/execution-scope foundation|execution scopes/i', $content);
        $this->assertMatchesPattern('/ResetParticipant|reset-participant/i', $content);
        $this->assertMatchesPattern('/execution identifiers?|execution context/i', $content);
        $this->assertMatchesPattern('/ExecutionOrchestrator|runtime-neutral execution orchestration/i', $content);
        $this->assertMatchesPattern('/quarantine/i', $content);
        $this->assertMatchesPattern('/generic Core instrumentation|generic execution-lifecycle observation/i', $content);
        $this->assertMatchesPattern('/minimal Core console foundation|runtime-neutral command foundation/i', $content);
        $this->assertMatchesPattern('/CommandRunner|CommandInput|CommandOutput|CommandResult/i', $content);
        $this->assertMatchesPattern('/CliCommand/i', $content);
        $this->assertMatchesPattern('/Phase 4\.1.*PSR.*HTTP.*middleware/is', $content);
        $this->assertMatchesPattern('/MiddlewarePipeline/i', $content);
        $this->assertMatchesPattern('/Phase 4\.2.*routing foundation/is', $content);
        $this->assertMatchesPattern('/RouteCollection|RouteMatcher/i', $content);
        $this->assertMatchesPattern('/Phase 4\.3.*routed handler dispatch/is', $content);
        $this->assertMatchesPattern('/RoutingRequestHandler/i', $content);
        $this->assertMatchesPattern('/RouteNotFound|MethodNotAllowed/i', $content);
        $this->assertMatchesPattern('/Phase 4\.4.*HTTP execution-kernel integration/is', $content);
        $this->assertMatchesPattern('/HttpKernel/i', $content);
        $this->assertMatchesPattern('/ExecutionOrchestrator.*HttpRequest|HttpRequest.*ExecutionOrchestrator/is', $content);
        $this->assertMatchesPattern('/ExecutionOutcome/i', $content);
        $this->assertMatchesPattern('/RouteNotFound.*empty 404|empty 404.*RouteNotFound/is', $content);
        $this->assertMatchesPattern('/MethodNotAllowed.*empty 405|empty 405.*MethodNotAllowed/is', $content);
        $this->assertMatchesPattern('/Phase 4\.5.*response\/error.*health foundation/is', $content);
        $this->assertMatchesPattern('/ExecutionOutcomeResponseResolver/i', $content);
        $this->assertMatchesPattern('/LivenessHandler|ReadinessHandler/i', $content);
        $this->assertMatchesPattern('/Phase 4\.6.*ResponseEmitter/is', $content);
        $this->assertMatchesPattern('/runtime-neutral response-emission boundary|runtime-neutral response emission boundary/i', $content);
        $this->assertMatchesPattern('/response resolution.*emission|emission.*response resolution/is', $content);
        $this->assertMatchesPattern('/Runtime.*concrete transmission|concrete transmission.*Runtime/is', $content);
        $this->assertMatchesPattern('/Runtime.*quarantine.*recycle.*termination|quarantine.*recycle.*termination.*Runtime/is', $content);
        $this->assertMatchesPattern('/no concrete SAPI emitter|concrete SAPI emitter.*not/i', $content);
        $this->assertMatchesPattern('/concrete PSR-7 implementation.*not|no concrete PSR-7 implementation/i', $content);
        $this->assertMatchesPattern('/Phase 4 HTTP package foundation.*complete|complete.*Phase 4 HTTP package foundation/i', $content);
        $this->assertMatchesPattern('/complete production runtime.*deferred|production runtime.*still.*deferred/i', $content);
        $this->assertMatchesPattern('/OpenTelemetry propagation.*deferred|trace context.*OpenTelemetry.*deferred/is', $content);
        $this->assertMatchesPattern('/Runtime adapters.*deferred|Runtime.*adapters.*deferred/i', $content);
        $this->assertMatchesPattern('/Insight.*Observe.*OpenTelemetry.*deferred/is', $content);

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

    public function testContractsReadmeDocumentsPhase51ComponentIdentityFoundation(): void
    {
        $content = $this->readProjectFile('packages/contracts/README.md');

        $this->assertMatchesPattern('/ComponentIdentifier/i', $content);
        $this->assertMatchesPattern('/ComponentType/i', $content);
        $this->assertMatchesPattern('/experimental/i', $content);
        $this->assertMatchesPattern('/identifier grammar/i', $content);
        $this->assertMatchesPattern('/no normalization|not normaliz/i', $content);
        $this->assertMatchesPattern('/Module.*Plugin.*identity vocabulary|Plugin.*Module.*identity vocabulary/is', $content);
        $this->assertMatchesPattern('/lifecycle.*deferred|deferred.*lifecycle/is', $content);
        $this->assertMatchesPattern('/descriptor.*deferred|deferred.*descriptor/is', $content);
    }

    public function testPackageOverviewDocumentsPhase51ComponentIdentityFoundationBoundary(): void
    {
        $content = $this->readProjectFile('packages/README.md');

        $this->assertMatchesPattern('/Phase 5\.1.*Component Identity Foundation/is', $content);
        $this->assertMatchesPattern('/experimental shared identity vocabulary|shared experimental identity vocabulary/i', $content);
        $this->assertMatchesPattern('/Module\/Plugin entry points.*not implemented|not implemented.*Module\/Plugin entry points/is', $content);
        $this->assertMatchesPattern('/descriptors?.*deferred|deferred.*descriptors?/i', $content);
        $this->assertMatchesPattern('/discovery.*deferred|deferred.*discovery/i', $content);
        $this->assertMatchesPattern('/registration.*deferred|deferred.*registration/i', $content);
        $this->assertMatchesPattern('/boot.*ready.*shutdown.*deferred|deferred.*boot.*ready.*shutdown/is', $content);
    }

    public function testPhase52ModuleAndPluginDescriptorFoundationIsDocumented(): void
    {
        $moduleReadme = $this->readProjectFile('packages/module/README.md');
        $pluginReadme = $this->readProjectFile('packages/plugin/README.md');
        $packagesReadme = $this->readProjectFile('packages/README.md');
        $changelog = $this->readProjectFile('CHANGELOG.md');

        foreach (array($moduleReadme, $pluginReadme, $packagesReadme, $changelog) as $content) {
            $this->assertMatchesPattern('/Phase 5\.2/i', $content);
            $this->assertMatchesPattern('/experimental/i', $content);
            $this->assertMatchesPattern('/immutable descriptor/i', $content);
            $this->assertMatchesPattern('/EvolvePHP-major compatibility validation|EvolvePHP major compatibility validation/i', $content);
            $this->assertMatchesPattern('/graph validation.*resolution.*deferred|dependency resolution.*deferred|deferred.*dependency resolution/is', $content);
            $this->assertMatchesPattern('/entry-point.*deferred|deferred.*entry-point|lifecycle.*deferred|deferred.*lifecycle/is', $content);
            $this->assertMatchesPattern('/discovery.*deferred|deferred.*discovery/is', $content);
            $this->assertMatchesPattern('/enablement.*deferred|deferred.*enablement/is', $content);
        }

        $this->assertMatchesPattern('/ModuleDescriptor/i', $moduleReadme);
        $this->assertMatchesPattern('/ComponentIdentifier/i', $moduleReadme);
        $this->assertMatchesPattern('/ComponentType::Module|hard-coded Module type/i', $moduleReadme);
        $this->assertMatchesPattern('/ModuleCompatibilityValidator/i', $moduleReadme);

        $this->assertMatchesPattern('/PluginDescriptor/i', $pluginReadme);
        $this->assertMatchesPattern('/ComponentIdentifier/i', $pluginReadme);
        $this->assertMatchesPattern('/ComponentType::Plugin|hard-coded Plugin type/i', $pluginReadme);
        $this->assertMatchesPattern('/PluginCompatibilityValidator/i', $pluginReadme);

        $this->assertMatchesPattern('/ModuleDescriptor/i', $packagesReadme);
        $this->assertMatchesPattern('/PluginDescriptor/i', $packagesReadme);
        $this->assertMatchesPattern('/not.*complete.*lifecycle|lifecycle.*not.*complete/is', $packagesReadme);
        $this->assertMatchesPattern('/Phase 5\.2.*ModuleDescriptor|ModuleDescriptor.*Phase 5\.2/is', $changelog);
        $this->assertMatchesPattern('/Phase 5\.2.*PluginDescriptor|PluginDescriptor.*Phase 5\.2/is', $changelog);
    }

    public function testPhase53aGraphDeclarationVocabularyIsDocumentedWithoutResolutionClaims(): void
    {
        $contractsReadme = $this->readProjectFile('packages/contracts/README.md');
        $moduleReadme = $this->readProjectFile('packages/module/README.md');
        $pluginReadme = $this->readProjectFile('packages/plugin/README.md');
        $packagesReadme = $this->readProjectFile('packages/README.md');
        $changelog = $this->readProjectFile('CHANGELOG.md');

        foreach (array($contractsReadme, $moduleReadme, $pluginReadme, $packagesReadme, $changelog) as $content) {
            $this->assertMatchesPattern('/Phase 5\.3A/i', $content);
            $this->assertMatchesPattern('/experimental/i', $content);
            $this->assertMatchesPattern('/declaration vocabulary|graph declaration vocabulary/i', $content);
            $this->assertMatchesPattern('/required.*optional|optional.*required/is', $content);
            $this->assertMatchesPattern('/conflicts?.*declarative|declarative.*conflicts?/is', $content);
            $this->assertMatchesPattern('/ExactlyOne|ExactlyOne/i', $content);
            $this->assertMatchesPattern('/OneOrMore/i', $content);
            $this->assertMatchesPattern('/provided capability identifiers?|capability identifiers?.*provided/is', $content);
            $this->assertMatchesPattern('/canonical.*order|order.*canonical/is', $content);
            $this->assertMatchesPattern('/startup-order semantics|startup order.*semantics|no startup-order/i', $content);
            $this->assertMatchesPattern('/does not.*resolve|resolution.*deferred|resolve.*deferred/is', $content);
            $this->assertMatchesPattern('/Phase 5\.3B/i', $content);
        }

        foreach (array($moduleReadme, $pluginReadme, $packagesReadme, $changelog) as $content) {
            $this->assertMatchesPattern('/graphDeclaration/i', $content);
            $this->assertMatchesPattern('/three-argument.*construction.*valid|construction.*three-argument.*valid/is', $content);
        }

        foreach (array($contractsReadme, $moduleReadme, $pluginReadme, $packagesReadme, $changelog) as $content) {
            $this->assertDoesNotMatchPattern('/composer\/semver/i', $content);
        }

        foreach (array($contractsReadme, $moduleReadme, $pluginReadme) as $content) {
            $this->assertDoesNotMatchPattern('/ProviderSelection|ProvidedCapability|CapabilityProvider|ProviderDescriptor/', $content);
        }
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

    public function testPhase53bCoreGraphResolutionIsDocumentedWithinAcceptedBoundaries(): void
    {
        $coreReadme = $this->readProjectFile('packages/core/README.md');
        $packagesReadme = $this->readProjectFile('packages/README.md');
        $changelog = $this->readProjectFile('CHANGELOG.md');

        foreach (array($coreReadme, $packagesReadme, $changelog) as $content) {
            $this->assertMatchesPattern('/Phase 5\.3B/i', $content);
            $this->assertMatchesPattern('/experimental/i', $content);
            $this->assertMatchesPattern('/Core-owned graph validation|Core-owned.*graph.*resolution|graph validation.*Core-owned/is', $content);
            $this->assertMatchesPattern('/ComponentGraphResolver/i', $content);
            $this->assertMatchesPattern('/ResolvedComponentGraph/i', $content);
            $this->assertMatchesPattern('/CapabilityProviderSelection/i', $content);
            $this->assertMatchesPattern('/consumer-scoped.*provider selection|provider selection.*consumer-scoped/is', $content);
            $this->assertMatchesPattern('/required.*optional|optional.*required/is', $content);
            $this->assertMatchesPattern('/ExactlyOne/i', $content);
            $this->assertMatchesPattern('/OneOrMore/i', $content);
            $this->assertMatchesPattern('/deterministic.*dependency-first|dependency-first.*deterministic/is', $content);
            $this->assertMatchesPattern('/cycle.*canonical|canonical.*cycle/i', $content);
            $this->assertMatchesPattern('/registration.*deferred|deferred.*registration/is', $content);
            $this->assertMatchesPattern('/boot.*ready.*shutdown.*deferred|deferred.*boot.*ready.*shutdown/is', $content);
            $this->assertMatchesPattern('/discovery.*enablement.*deferred|deferred.*discovery.*enablement/is', $content);
            $this->assertDoesNotMatchPattern('/composer\/semver/i', $content);
            $this->assertDoesNotMatchPattern('/implements?.*SemVer|SemVer.*implemented/i', $content);
        }

        foreach (array($coreReadme, $packagesReadme) as $content) {
            $this->assertMatchesPattern('/Contracts declarations|Contracts graph declarations|consumes.*ComponentGraphDeclaration/is', $content);
            $this->assertMatchesPattern('/conflict.*validation|validates.*conflicts?/is', $content);
        }
    }

    public function testCoreReadmeDocumentsConsoleFoundationBoundary(): void
    {
        $content = $this->readProjectFile('packages/core/README.md');

        $this->assertMatchesPattern('/minimal runtime-neutral command foundation/i', $content);
        $this->assertMatchesPattern('/CommandRunner.*ExecutionOrchestrator.*CliCommand/is', $content);
        $this->assertMatchesPattern('/CommandInput.*raw ordered.*token/i', $content);
        $this->assertMatchesPattern('/CommandOutput.*runtime-neutral/i', $content);
        $this->assertMatchesPattern('/CommandResult.*exit status/i', $content);
        $this->assertMatchesPattern('/no shell executable|does not provide a shell executable/i', $content);
        $this->assertMatchesPattern('/Doctor.*generator.*deferred/is', $content);
    }

    public function testHttpPackageDeclaresPsr17ResponseFactoryWithoutConcretePsr7Implementation(): void
    {
        $httpManifest = $this->readJsonFile('packages/http/composer.json');

        $this->assertSame('^1.0', $httpManifest['require']['psr/http-factory'] ?? null);

        foreach ($this->concretePsr7Implementations() as $packageName) {
            $this->assertArrayNotHasKey(
                $packageName,
                $httpManifest['require'],
                'HTTP must not require a concrete PSR-7 implementation for Phase 4.5.'
            );
        }
    }

    public function testHttpReadmeDocumentsPhase46ResponseEmitterAndRuntimeBoundaries(): void
    {
        $content = $this->readProjectFile('packages/http/README.md');

        $this->assertMatchesPattern('/psr\/http-factory/i', $content);
        $this->assertMatchesPattern('/PSR-17.*ResponseFactoryInterface|ResponseFactoryInterface.*PSR-17/is', $content);
        $this->assertMatchesPattern('/concrete PSR-7 implementation.*not bundled|not bundle.*concrete PSR-7 implementation/is', $content);
        $this->assertMatchesPattern('/ExecutionOutcomeResponseResolver/i', $content);
        $this->assertMatchesPattern('/after.*HttpKernel/is', $content);
        $this->assertMatchesPattern('/RouteNotFound.*404|404.*RouteNotFound/is', $content);
        $this->assertMatchesPattern('/MethodNotAllowed.*405|405.*MethodNotAllowed/is', $content);
        $this->assertMatchesPattern('/Allow/i', $content);
        $this->assertMatchesPattern('/Throwable.*500|500.*Throwable/is', $content);
        $this->assertMatchesPattern('/cleanup.*instrumentation.*reuse|reuse.*cleanup.*instrumentation/is', $content);
        $this->assertMatchesPattern('/ExecutionStartFailed.*runtime concern|runtime concern.*ExecutionStartFailed/is', $content);
        $this->assertMatchesPattern('/ReadinessCheck/i', $content);
        $this->assertMatchesPattern('/LivenessHandler/i', $content);
        $this->assertMatchesPattern('/ReadinessHandler/i', $content);
        $this->assertMatchesPattern('/not auto-routed|no automatic health routes|not automatically register/is', $content);
        $this->assertMatchesPattern('/empty.*bod/i', $content);
        $this->assertMatchesPattern('/false.*503|throwing.*503|503.*false|503.*throwing/is', $content);
        $this->assertMatchesPattern('/details.*not exposed|not expose.*details/is', $content);
        $this->assertMatchesPattern('/ResponseEmitter/i', $content);
        $this->assertMatchesPattern('/emit\s*\(\s*ResponseInterface\s+\$response\s*\)\s*:\s*void/i', $content);
        $this->assertMatchesPattern('/emitter.*receives.*response only|receives only.*response/i', $content);
        $this->assertMatchesPattern('/does not receive.*ExecutionOutcome|ExecutionOutcome.*does not receive/is', $content);
        $this->assertMatchesPattern('/HttpKernel.*ExecutionOutcome.*ExecutionOutcomeResponseResolver.*ResponseInterface.*ResponseEmitter/is', $content);
        $this->assertMatchesPattern('/no automatic emission|does not auto-emit|explicit emission/i', $content);
        $this->assertMatchesPattern('/HttpKernel.*does not.*auto-emit|does not.*auto-emit.*HttpKernel/i', $content);
        $this->assertMatchesPattern('/resolver.*does not.*auto-emit|does not.*auto-emit.*resolver/i', $content);
        $this->assertMatchesPattern('/runtime-specific implementations?.*deferred|deferred.*runtime-specific implementations?/i', $content);
        $this->assertMatchesPattern('/transmission failure.*deferred|deferred.*transmission failure/i', $content);
        $this->assertMatchesPattern('/SAPI.*output functions.*not provided|header.*output.*not provided/is', $content);
        $this->assertMatchesPattern('/quarantine.*recycle.*termination.*Runtime|Runtime.*quarantine.*recycle.*termination/is', $content);
    }

    public function testDevelopmentGuideDocumentsPsr17WithinPsrHttpMessageLayer(): void
    {
        $content = $this->readProjectFile('DEVELOPMENT.md');

        $this->assertMatchesPattern('/PsrHttpMessage/i', $content);
        $this->assertMatchesPattern('/PSR-7.*message interfaces/i', $content);
        $this->assertMatchesPattern('/PSR-17.*factory interfaces/i', $content);
        $this->assertMatchesPattern('/PsrHttpServer.*PSR-15/is', $content);
        $this->assertMatchesPattern('/psr\/http-factory/i', $content);
        $this->assertMatchesPattern('/Psr\\\\Http\\\\Message|Psr\\\Http\\\Message|Psr\\\\\\\\Http\\\\\\\\Message/i', $content);
        $this->assertMatchesPattern('/no new Deptrac.*layer|does not require.*new Deptrac/is', $content);
    }

    public function testChangelogRecordsPhase21PackageSkeletonPhase37ConsoleFoundationPhase41HttpFoundationPhase42RoutingPhase43RoutedDispatchPhase44HttpKernelPhase45ResponseHealthPhase46EmitterAndPhase51ComponentIdentity(): void
    {
        $content = $this->readProjectFile('CHANGELOG.md');

        $this->assertMatchesPattern('/##\s+\[?Unreleased\]?/i', $content);
        $this->assertMatchesPattern('/Phase 2\.1/i', $content);
        $this->assertMatchesPattern('/initial EvolvePHP 2 package skeleton/i', $content);
        $this->assertMatchesPattern('/Phase 3\.4/i', $content);
        $this->assertMatchesPattern('/execution-scope and reset foundation/i', $content);
        $this->assertMatchesPattern('/ResetParticipant/i', $content);
        $this->assertMatchesPattern('/Phase 3\.5/i', $content);
        $this->assertMatchesPattern('/runtime-neutral execution orchestration/i', $content);
        $this->assertMatchesPattern('/Phase 3\.6/i', $content);
        $this->assertMatchesPattern('/generic execution observations/i', $content);
        $this->assertMatchesPattern('/instrumentation-failure reporting/i', $content);
        $this->assertMatchesPattern('/Phase 3\.7/i', $content);
        $this->assertMatchesPattern('/runtime-neutral.*Command.*contract/i', $content);
        $this->assertMatchesPattern('/CommandRunner.*ExecutionOrchestrator/is', $content);
        $this->assertMatchesPattern('/CliCommand/i', $content);
        $this->assertMatchesPattern('/unknown-command dispatch boundary/i', $content);
        $this->assertMatchesPattern('/quarantine/i', $content);
        $this->assertMatchesPattern('/Phase 4\.1/i', $content);
        $this->assertMatchesPattern('/PSR.*HTTP.*interoperability/i', $content);
        $this->assertMatchesPattern('/MiddlewarePipeline/i', $content);
        $this->assertMatchesPattern('/Phase 4\.2/i', $content);
        $this->assertMatchesPattern('/routing foundation/i', $content);
        $this->assertMatchesPattern('/RouteMatcher/i', $content);
        $this->assertMatchesPattern('/Phase 4\.3/i', $content);
        $this->assertMatchesPattern('/routed handler dispatch/i', $content);
        $this->assertMatchesPattern('/RoutingRequestHandler/i', $content);
        $this->assertMatchesPattern('/RouteNotFound.*MethodNotAllowed|MethodNotAllowed.*RouteNotFound/i', $content);
        $this->assertMatchesPattern('/Phase 4\.4/i', $content);
        $this->assertMatchesPattern('/HTTP execution-kernel integration/i', $content);
        $this->assertMatchesPattern('/HttpKernel.*ExecutionOrchestrator|ExecutionOrchestrator.*HttpKernel/i', $content);
        $this->assertMatchesPattern('/ExecutionOutcome/i', $content);
        $this->assertMatchesPattern('/Phase 4\.5/i', $content);
        $this->assertMatchesPattern('/response\/error.*health foundation|health foundation.*response\/error/is', $content);
        $this->assertMatchesPattern('/ExecutionOutcomeResponseResolver/i', $content);
        $this->assertMatchesPattern('/ReadinessCheck|LivenessHandler|ReadinessHandler/i', $content);
        $this->assertMatchesPattern('/Phase 4\.6/i', $content);
        $this->assertMatchesPattern('/ResponseEmitter/i', $content);
        $this->assertMatchesPattern('/Phase 5\.1/i', $content);
        $this->assertMatchesPattern('/Component Identity Foundation/i', $content);
        $this->assertDoesNotMatchPattern('/reserved-but-rejected/i', $content);
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
                'require' => array(
                    'php' => '^8.4',
                    'evolvephp/contracts' => '^2.0',
                    'evolvephp/core' => '^2.0',
                    'psr/http-factory' => '^1.0',
                    'psr/http-message' => '^1.1 || ^2.0',
                    'psr/http-server-handler' => '^1.0',
                    'psr/http-server-middleware' => '^1.0',
                ),
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

    private function acceptedPackageSourceInventories()
    {
        return array(
            'packages/contracts/src' => array(
                'Component/CapabilityCardinality.php',
                'Component/CapabilityIdentifier.php',
                'Component/CapabilityRequirement.php',
                'Component/ComponentConflict.php',
                'Component/ComponentDependency.php',
                'Component/ComponentDependencyKind.php',
                'Component/ComponentGraphDeclaration.php',
                'Component/ComponentGraphRelations.php',
                'Component/ComponentIdentifier.php',
                'Component/ComponentType.php',
                'Configuration/Configuration.php',
                'Configuration/ConfigurationValidator.php',
                'Exception/ConfigurationException.php',
                'Exception/EvolveException.php',
                'Exception/LifecycleException.php',
                'Execution/ResetParticipant.php',
                'Lifecycle/ApplicationLifecycle.php',
            ),
            'packages/core/src' => array(
                'ApplicationKernel.php',
                'Component/CapabilityProviderSelection.php',
                'Component/ComponentGraphResolver.php',
                'Component/ResolvedComponentGraph.php',
                'Configuration/ArrayConfiguration.php',
                'Console/Command.php',
                'Console/CommandInput.php',
                'Console/CommandOutput.php',
                'Console/CommandRegistry.php',
                'Console/CommandResult.php',
                'Console/CommandRunner.php',
                'Container/ExecutionScopeContainer.php',
                'Container/ServiceContainer.php',
                'Container/ServiceDefinition.php',
                'Container/ServiceLifetime.php',
                'Container/ServiceRegistry.php',
                'Exception/ActiveComponentConflict.php',
                'Exception/AmbiguousCapabilityProvider.php',
                'Exception/CommandNotFound.php',
                'Exception/ComponentDependencyCycle.php',
                'Exception/ComponentGraphResolutionFailed.php',
                'Exception/ConfigurationValidationFailed.php',
                'Exception/DuplicateComponentIdentifier.php',
                'Exception/ExecutionResetFailed.php',
                'Exception/ExecutionScopeClosed.php',
                'Exception/ExecutionScopeUnavailable.php',
                'Exception/ExecutionStartFailed.php',
                'Exception/InvalidCapabilityProviderSelection.php',
                'Exception/InvalidCommandDefinition.php',
                'Exception/InvalidConfiguration.php',
                'Exception/InvalidLifecycleTransition.php',
                'Exception/InvalidResetParticipant.php',
                'Exception/InvalidServiceDefinition.php',
                'Exception/MissingCapabilityProvider.php',
                'Exception/MissingComponentDependency.php',
                'Exception/ServiceNotFound.php',
                'Exception/ServiceRegistryFrozen.php',
                'Exception/ServiceResolutionFailed.php',
                'Execution/ExecutionContext.php',
                'Execution/ExecutionIdentifier.php',
                'Execution/ExecutionKind.php',
                'Execution/ExecutionOrchestrator.php',
                'Execution/ExecutionOutcome.php',
                'Execution/ExecutionScope.php',
                'Execution/ProcessReuseDecision.php',
                'Execution/ResetCoordinator.php',
                'Instrumentation/InstrumentationFailure.php',
                'Instrumentation/Observation.php',
                'Instrumentation/ObservationDispatcher.php',
                'Instrumentation/ObservationOutcome.php',
                'Instrumentation/ObservationSink.php',
                'Instrumentation/ObservationType.php',
                'Lifecycle/ApplicationState.php',
            ),
            'packages/http/src' => array(
                'Exception/MethodNotAllowed.php',
                'Exception/RouteNotFound.php',
                'Health/LivenessHandler.php',
                'Health/ReadinessCheck.php',
                'Health/ReadinessHandler.php',
                'HttpKernel.php',
                'Middleware/Internal/MiddlewareDispatcher.php',
                'Middleware/MiddlewarePipeline.php',
                'Response/ExecutionOutcomeResponseResolver.php',
                'Response/ResponseEmitter.php',
                'Routing/Internal/RoutePattern.php',
                'Routing/Route.php',
                'Routing/RouteCollection.php',
                'Routing/RouteMatch.php',
                'Routing/RouteMatcher.php',
                'Routing/RoutingRequestHandler.php',
            ),
            'packages/module/src' => array(
                'Exception/IncompatibleModuleDescriptor.php',
                'ModuleCompatibilityValidator.php',
                'ModuleDescriptor.php',
            ),
            'packages/plugin/src' => array(
                'Exception/IncompatiblePluginDescriptor.php',
                'PluginCompatibilityValidator.php',
                'PluginDescriptor.php',
            ),
            'packages/testing/src' => array(),
        );
    }

    private function concretePsr7Implementations()
    {
        return array(
            'guzzlehttp/psr7',
            'laminas/laminas-diactoros',
            'nyholm/psr7',
            'slim/psr7',
            'symfony/psr-http-message-bridge',
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
