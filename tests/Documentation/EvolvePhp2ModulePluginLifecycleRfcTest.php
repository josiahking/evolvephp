<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2ModulePluginLifecycleRfcTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testRfc0004ExistsWithAcceptedMetadata(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0004-module-and-plugin-lifecycle.md');

        $this->assertMatchesPattern('/#\s*RFC\s+0004:\s*Module and Plugin Lifecycle/i', $content);
        $this->assertMatchesPattern('/Status:\s*Accepted/i', $content);
        $this->assertMatchesPattern('/Target release:\s*EvolvePHP 2\.0/i', $content);
        $this->assertMatchesPattern('/Decision type:\s*Module, plugin and application lifecycle architecture/i', $content);
        $this->assertMatchesPattern('/Depends on:\s*RFC 0001,\s*RFC 0002,\s*RFC 0003/i', $content);
    }

    public function testLifecycleSummaryAndTerminologyAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0004-module-and-plugin-lifecycle.md');

        $this->assertMatchesPattern('/discovery\s+validation\s+dependency resolution\s+registration\s+boot\s+ready\s+shutdown/is', $content);
        $this->assertMatchesPattern('/module represents an application business capability/i', $content);
        $this->assertMatchesPattern('/plugin extends framework or platform behaviour/i', $content);
        $this->assertMatchesPattern('/Modules and plugins are not interchangeable terms/i', $content);
        $this->assertMatchesPattern('/Descriptor/i', $content);
        $this->assertMatchesPattern('/Capability/i', $content);
        $this->assertMatchesPattern('/Reset.*RFC 0005/is', $content);
    }

    public function testDiscoveryDescriptorIdentifierAndEnablementRulesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0004-module-and-plugin-lifecycle.md');

        $this->assertMatchesPattern('/Descriptor parsing must not instantiate the component/i', $content);
        $this->assertMatchesPattern('/Recursive filesystem scanning is not the default/i', $content);
        $this->assertMatchesPattern('/Every enabled module and plugin has one stable machine identifier/i', $content);
        $this->assertMatchesPattern('/Duplicate identifiers are fatal/i', $content);
        $this->assertMatchesPattern('/Application configuration controls which optional modules and plugins are enabled/i', $content);
        $this->assertMatchesPattern('/Disabled components are not registered or booted/i', $content);
        $this->assertMatchesPattern('/required dependency on a disabled component is a startup error/i', $content);
        $this->assertMatchesPattern('/Optional dependencies.*Do not prevent startup when absent/is', $content);
    }

    public function testDependencyCapabilityGraphAndOrderingRulesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0004-module-and-plugin-lifecycle.md');

        $this->assertMatchesPattern('/Required capabilities must have a valid provider/i', $content);
        $this->assertMatchesPattern('/Dependency cycles are fatal/i', $content);
        $this->assertMatchesPattern('/Cycle errors must report the detected dependency chain/i', $content);
        $this->assertMatchesPattern('/same descriptor set and configuration must produce the same order/i', $content);
        $this->assertMatchesPattern('/Dependencies register and boot before their dependents/i', $content);
        $this->assertMatchesPattern('/Arbitrary numeric startup priority is not part of the foundational lifecycle/i', $content);
        $this->assertMatchesPattern('/Filesystem enumeration order must never determine lifecycle order/i', $content);
    }

    public function testRegistrationBootFreezeReadyAndFailureRulesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0004-module-and-plugin-lifecycle.md');

        $this->assertMatchesPattern('/Registration and boot are separate phases/i', $content);
        $this->assertMatchesPattern('/Components must not resolve runtime services during registration/i', $content);
        $this->assertMatchesPattern('/definition graph is frozen before boot/i', $content);
        $this->assertMatchesPattern('/Boot occurs after all registrations succeed/i', $content);
        $this->assertMatchesPattern('/Registration failure aborts application startup/i', $content);
        $this->assertMatchesPattern('/Boot failure aborts application readiness/i', $content);
        $this->assertMatchesPattern('/Successfully booted components shut down in reverse boot order/i', $content);
        $this->assertMatchesPattern('/original boot failure remains the primary error/i', $content);
        $this->assertMatchesPattern('/application becomes ready only when/i', $content);
    }

    public function testShutdownAtMostOnceConfigurationAndContainerRulesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0004-module-and-plugin-lifecycle.md');

        $this->assertMatchesPattern('/Shutdown runs in reverse successful boot order/i', $content);
        $this->assertMatchesPattern('/One shutdown failure must not prevent remaining shutdown operations/i', $content);
        $this->assertMatchesPattern('/registered at most once/i', $content);
        $this->assertMatchesPattern('/booted at most once/i', $content);
        $this->assertMatchesPattern('/shut down at most once/i', $content);
        $this->assertMatchesPattern('/Module and plugin configuration is namespaced/i', $content);
        $this->assertMatchesPattern('/Configuration is validated before registration or boot/i', $content);
        $this->assertMatchesPattern('/Components must not access the container through global state/i', $content);
    }

    public function testModulePluginTrustRuntimeBridgeAndObservabilityBoundariesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0004-module-and-plugin-lifecycle.md');

        $this->assertMatchesPattern('/Modules:\s*\R\s*[-*] Represent business capabilities/i', $content);
        $this->assertMatchesPattern('/Plugins:\s*\R\s*[-*] Extend framework or platform behaviour/i', $content);
        $this->assertMatchesPattern('/trusted code and can execute with the privileges of the application process/i', $content);
        $this->assertMatchesPattern('/lifecycle system is not a security sandbox/i', $content);
        $this->assertMatchesPattern('/Application-lifetime instances must not retain request, message, job, user or tenant state/i', $content);
        $this->assertMatchesPattern('/detailed scope ownership, reset ordering and leak detection belong to RFC 0005/i', $content);
        $this->assertMatchesPattern('/host framework owns the top-level process and application lifecycle/i', $content);
        $this->assertMatchesPattern('/Core must not depend on Insight/i', $content);
        $this->assertMatchesPattern('/Core must not depend on Observe/i', $content);
        $this->assertMatchesPattern('/Runtime enabling is unsupported/i', $content);
        $this->assertMatchesPattern('/Dynamic unloading is unsupported/i', $content);
    }

    public function testTestingAlternativesConsequencesIndexAndChangelogAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0004-module-and-plugin-lifecycle.md');
        $index = $this->readProjectFile('docs/rfcs/README.md');
        $changelog = $this->readProjectFile('CHANGELOG.md');

        $this->assertMatchesPattern('/Testing Requirements/i', $content);
        $this->assertMatchesPattern('/Architecture Enforcement Direction/i', $content);
        $this->assertMatchesPattern('/Consequences and Tradeoffs/i', $content);
        $this->assertMatchesPattern('/Alternatives Considered/i', $content);
        $this->assertMatchesPattern('/Governance/i', $content);
        $this->assertMatchesPattern('/0004-module-and-plugin-lifecycle\.md/i', $index);
        $this->assertMatchesPattern('/\[RFC 0004: Module and Plugin Lifecycle\]\(0004-module-and-plugin-lifecycle\.md\) - Accepted/i', $index);
        $this->assertMatchesPattern('/\[RFC 0005: Request Scope, Runtime Reset and Persistent-Worker Safety\]\(0005-request-scope-runtime-reset-and-persistent-worker-safety\.md\) - Accepted/i', $index);
        $this->assertMatchesPattern('/RFC 0004/i', $changelog);
        $this->assertMatchesPattern('/Module and Plugin Lifecycle/i', $changelog);
    }

    private function readProjectFile($path)
    {
        $fullPath = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        $this->assertFileExists($fullPath, $path . ' should exist before it is read.');

        return file_get_contents($fullPath);
    }

    private function assertMatchesPattern($pattern, $content)
    {
        $this->assertSame(1, preg_match($pattern, $content), 'Failed asserting that content matches ' . $pattern);
    }
}
