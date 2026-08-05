<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2PackageBoundariesRfcTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testRfc0002ExistsWithAcceptedMetadata(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0002-terminology-package-boundaries-and-public-contracts.md');

        $this->assertMatchesPattern('/#\s*RFC\s+0002:\s*Terminology, Package Boundaries and Public Contracts/i', $content);
        $this->assertMatchesPattern('/Status:\s*Accepted/i', $content);
        $this->assertMatchesPattern('/Target release:\s*EvolvePHP 2\.0/i', $content);
        $this->assertMatchesPattern('/Decision type:\s*Package architecture and public API governance/i', $content);
        $this->assertMatchesPattern('/Depends on:\s*RFC 0001/i', $content);
    }

    public function testVendorNamespaceAndPackageMapAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0002-terminology-package-boundaries-and-public-contracts.md');

        $this->assertMatchesPattern('/Composer vendor:\s*evolvephp/i', $content);
        $this->assertMatchesPattern('/Framework namespace root:\s*Evolve\\\\/i', $content);
        $this->assertMatchesPattern('/Product-area names and Composer package names are related but not identical/i', $content);
        $this->assertMatchesPattern('/product area may contain (?:one or more|several) Composer packages/i', $content);
        $this->assertMatchesPattern('/modular monorepo/i', $content);
        $this->assertMatchesPattern('/evolvephp\/contracts/i', $content);
        $this->assertMatchesPattern('/evolvephp\/core/i', $content);
        $this->assertMatchesPattern('/evolvephp\/http/i', $content);
        $this->assertMatchesPattern('/evolvephp\/module/i', $content);
        $this->assertMatchesPattern('/evolvephp\/plugin/i', $content);
        $this->assertMatchesPattern('/evolvephp\/testing/i', $content);
        $this->assertMatchesPattern('/evolvephp\/insight/i', $content);
        $this->assertMatchesPattern('/evolvephp\/observe/i', $content);
        $this->assertMatchesPattern('/evolvephp\/bridge-\*/i', $content);
        $this->assertMatchesPattern('/evolvephp\/runtime-\*/i', $content);
    }

    public function testNamespaceOwnershipAndApplicationBoundariesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0002-terminology-package-boundaries-and-public-contracts.md');

        $this->assertMatchesPattern('/Third-party.*must not.*Evolve\\\\/is', $content);
        $this->assertMatchesPattern('/Application modules are not required to use an `?Evolve\\\\`? namespace/i', $content);
        $this->assertMatchesPattern('/framework must not claim ownership of application domain namespaces/i', $content);
        $this->assertMatchesPattern('/Application modules are owned by the application/i', $content);
        $this->assertMatchesPattern('/Plugins extend framework or platform behaviour/i', $content);
    }

    public function testDependencyDirectionAndCycleRulesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0002-terminology-package-boundaries-and-public-contracts.md');

        $this->assertMatchesPattern('/Circular Composer dependencies are forbidden/i', $content);
        $this->assertMatchesPattern('/contracts\s*->\s*core/i', $content);
        $this->assertMatchesPattern('/core\s*->\s*insight/i', $content);
        $this->assertMatchesPattern('/core\s*->\s*observe/i', $content);
        $this->assertMatchesPattern('/core\s*->\s*bridge-\*/i', $content);
        $this->assertMatchesPattern('/core\s*->\s*runtime-specific SDKs/i', $content);
        $this->assertMatchesPattern('/observe\s*->\s*insight/i', $content);
        $this->assertMatchesPattern('/production package\s*->\s*testing/i', $content);
        $this->assertMatchesPattern('/Evolve Deploy is outside the (?:framework runtime|Core) dependency graph/i', $content);
        $this->assertMatchesPattern('/Core must not depend on optional product packages/i', $content);
    }

    public function testPublicApiClassificationsAndVersioningAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0002-terminology-package-boundaries-and-public-contracts.md');

        $this->assertMatchesPattern('/Stable public API/i', $content);
        $this->assertMatchesPattern('/Experimental API/i', $content);
        $this->assertMatchesPattern('/Internal API/i', $content);
        $this->assertMatchesPattern('/Deprecated API/i', $content);
        $this->assertMatchesPattern('/PHP visibility.*not sufficient|visibility.*does not define support guarantees/is', $content);
        $this->assertMatchesPattern('/Behavioural tests for every public contract/i', $content);
        $this->assertMatchesPattern('/Documentation and changelog entries for material public API additions/i', $content);
        $this->assertMatchesPattern('/major version `?2`?/i', $content);
        $this->assertMatchesPattern('/2\.0\.0-alpha\.1/i', $content);
        $this->assertMatchesPattern('/2\.0\.0-beta\.1/i', $content);
        $this->assertMatchesPattern('/2\.0\.0-rc\.1/i', $content);
        $this->assertMatchesPattern('/Package publication policy/i', $content);
    }

    public function testInsightObserveBridgeRuntimeAndTestingBoundariesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0002-terminology-package-boundaries-and-public-contracts.md');

        $this->assertMatchesPattern('/Insight versus Observe boundary/i', $content);
        $this->assertMatchesPattern('/Observe must not depend on Insight/i', $content);
        $this->assertMatchesPattern('/Insight must not be required for production telemetry/i', $content);
        $this->assertMatchesPattern('/Bridge adapters are outside Core/i', $content);
        $this->assertMatchesPattern('/Host-framework dependencies stay in their matching adapter package/i', $content);
        $this->assertMatchesPattern('/Core defines runtime-neutral lifecycle behaviour/i', $content);
        $this->assertMatchesPattern('/Core must not import FrankenPHP or RoadRunner SDK types/i', $content);
        $this->assertMatchesPattern('/Framework production packages must not depend on testing packages/i', $content);
    }

    public function testRfcGovernanceSectionsIndexAndChangelogAreUpdated(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0002-terminology-package-boundaries-and-public-contracts.md');
        $index = $this->readProjectFile('docs/rfcs/README.md');
        $changelog = $this->readProjectFile('CHANGELOG.md');

        $this->assertMatchesPattern('/Consequences and tradeoffs/i', $content);
        $this->assertMatchesPattern('/Alternatives considered/i', $content);
        $this->assertMatchesPattern('/Decision governance/i', $content);
        $this->assertMatchesPattern('/0002-terminology-package-boundaries-and-public-contracts\.md/i', $index);
        $this->assertMatchesPattern('/\[RFC 0001: EvolvePHP 2 Vision, Scope and Non-Goals\]\(0001-evolvephp-2-vision-and-scope\.md\) - Accepted/i', $index);
        $this->assertMatchesPattern('/\[RFC 0002: Terminology, Package Boundaries and Public Contracts\]\(0002-terminology-package-boundaries-and-public-contracts\.md\) - Accepted/i', $index);
        $this->assertMatchesPattern('/RFC 0002/i', $changelog);
        $this->assertMatchesPattern('/Terminology, Package Boundaries and Public Contracts/i', $changelog);
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
