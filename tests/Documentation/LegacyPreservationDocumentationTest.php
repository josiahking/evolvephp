<?php

use PHPUnit\Framework\TestCase;

final class LegacyPreservationDocumentationTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testRequiredPreservationDocumentsExist(): void
    {
        $paths = array(
            'AGENTS.md',
            'SECURITY.md',
            'SUPPORT.md',
            'docs/history/evolvephp-1-overview.md',
            'docs/history/original-architecture.md',
            'docs/history/known-risks-and-limitations.md',
            'docs/history/production-usage.md',
            'docs/history/lessons-learned.md',
        );

        foreach ($paths as $path) {
            $this->assertFileExists($this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path), $path . ' should exist.');
        }
    }

    public function testChangelogRecordsLegacyBaselineGovernanceWork(): void
    {
        $content = $this->readProjectFile('CHANGELOG.md');

        $this->assertMatchesPattern('/##\s+\[?Unreleased\]?/i', $content);
        $this->assertMatchesPattern('/documentation|governance/i', $content);
        $this->assertMatchesPattern('/legacy preservation|preserved legacy baseline|legacy baseline/i', $content);
        $this->assertStringContainsString('AGENTS.md', $content);
        $this->assertMatchesPattern('/SECURITY\.md|security policy/i', $content);
        $this->assertMatchesPattern('/SUPPORT\.md|support policy/i', $content);
        $this->assertStringContainsString('2da5da7866f65d314a0e2bf10b572004b3014d60', $content);
    }

    public function testAgentRulesContainRequiredGovernance(): void
    {
        $content = $this->readProjectFile('AGENTS.md');

        $this->assertMatchesPattern('/Every coding agent must read and follow this file/i', $content);
        $this->assertMatchesPattern('/RED\s*->\s*GREEN\s*->\s*REFACTOR/i', $content);
        $this->assertMatchesPattern('/Write or update tests before implementation/i', $content);
        $this->assertMatchesPattern('/Do not work directly on `?master`?/i', $content);
        $this->assertMatchesPattern('/clean working tree/i', $content);
        $this->assertMatchesPattern('/Do not push, merge, tag or open a pull request/i', $content);
        $this->assertMatchesPattern('/Every final agent report must include/i', $content);
        $this->assertMatchesPattern('/Commands executed/i', $content);
        $this->assertMatchesPattern('/Test results/i', $content);
    }

    public function testLegacyOverviewIdentifiesPreservedBaseline(): void
    {
        $content = $this->readProjectFile('docs/history/evolvephp-1-overview.md');

        $this->assertStringContainsString('2da5da7866f65d314a0e2bf10b572004b3014d60', $content);
        $this->assertMatchesPattern('/EvolvePHP 1.*preserved legacy line|preserved legacy.*EvolvePHP 1/is', $content);
        $this->assertMatchesPattern('/default legacy branch.*master|master.*default legacy branch/is', $content);
        $this->assertMatchesPattern('/EvolvePHP 2.*separate redesign|separate redesign.*EvolvePHP 2/is', $content);
    }

    public function testRisksDocumentWarnsAgainstNewProductionStarts(): void
    {
        $content = $this->readProjectFile('docs/history/known-risks-and-limitations.md');

        $this->assertMatchesPattern('/not recommended.*starting point.*new production application/is', $content);
        $this->assertMatchesPattern('/Security/i', $content);
        $this->assertMatchesPattern('/Correctness/i', $content);
        $this->assertMatchesPattern('/Maintainability/i', $content);
        $this->assertMatchesPattern('/Compatibility/i', $content);
        $this->assertMatchesPattern('/Scalability/i', $content);
        $this->assertMatchesPattern('/Developer experience/i', $content);
        $this->assertMatchesPattern('/Documentation/i', $content);
    }

    public function testSecurityAndSupportPoliciesGiveRequiredDirections(): void
    {
        $security = $this->readProjectFile('SECURITY.md');
        $support = $this->readProjectFile('SUPPORT.md');

        $this->assertMatchesPattern('/do not.*public GitHub issues|not.*public issues/is', $security);
        $this->assertMatchesPattern('/EvolvePHP 1.*legacy|legacy.*EvolvePHP 1/is', $security);
        $this->assertMatchesPattern('/EvolvePHP 2.*under development|under development.*EvolvePHP 2/is', $security);
        $this->assertMatchesPattern('/coordinated disclosure/i', $security);

        $this->assertMatchesPattern('/usage questions/i', $support);
        $this->assertMatchesPattern('/bug reports/i', $support);
        $this->assertMatchesPattern('/security reports/i', $support);
        $this->assertMatchesPattern('/EvolvePHP 1.*legacy support|legacy support.*EvolvePHP 1/is', $support);
        $this->assertMatchesPattern('/EvolvePHP 2.*development|development.*EvolvePHP 2/is', $support);
        $this->assertStringContainsString('SECURITY.md', $support);
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
