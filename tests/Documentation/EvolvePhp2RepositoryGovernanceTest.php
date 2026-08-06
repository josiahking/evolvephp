<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2RepositoryGovernanceTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testReadmeDocumentsStageABranchIdentity()
    {
        $content = $this->readProjectFile('README.md');

        $this->assertMatchesPattern('/2\.x.*designated EvolvePHP 2 development branch|designated EvolvePHP 2 development branch.*2\.x/is', $content);
        $this->assertMatchesPattern('/master.*preserved EvolvePHP 1 legacy branch|preserved EvolvePHP 1 legacy branch.*master/is', $content);
        $this->assertMatchesPattern('/EvolvePHP 2 changes.*must not target.*master|must not target.*master.*EvolvePHP 2 changes/is', $content);
        $this->assertMatchesPattern('/current GitHub default.*master.*Phase 2\.7 external transition|Phase 2\.7 external transition.*current GitHub default.*master/is', $content);
        $this->assertDoesNotMatchPattern($this->unsupportedDefaultBranchClaimPattern(), $content);
    }

    public function testAgentRulesDocumentVerifiedBaseBranchWorkflow()
    {
        $content = $this->readProjectFile('AGENTS.md');

        $this->assertMatchesPattern('/EvolvePHP 2.*based on.*current `2\.x`|current `2\.x`.*EvolvePHP 2.*based on/is', $content);
        $this->assertMatchesPattern('/task-specific branch/i', $content);
        $this->assertMatchesPattern('/verify.*requested base branch.*SHA|requested base branch.*SHA.*verify/is', $content);
        $this->assertMatchesPattern('/must not infer.*GitHub.*default branch|GitHub.*default branch.*must not infer/is', $content);
        $this->assertMatchesPattern('/master.*approved legacy maintenance|approved legacy maintenance.*master/is', $content);
        $this->assertMatchesPattern('/legacy maintenance.*clearly requested|clearly requested.*legacy maintenance/is', $content);
        $this->assertMatchesPattern('/approved legacy-maintenance task.*start from `master`|start from `master`.*approved legacy-maintenance task/is', $content);
        $this->assertMatchesPattern('/direct work on `?master`?.*prohibited|prohibited.*direct work on `?master`?/is', $content);
        $this->assertMatchesPattern('/EvolvePHP 2 changes.*never.*merged into `?master`?|never.*EvolvePHP 2 changes.*`?master`?/is', $content);
        $this->assertMatchesPattern('/EvolvePHP 1 changes.*not.*mixed into `2\.x`|not.*EvolvePHP 1 changes.*`2\.x`/is', $content);
    }

    public function testSupportPolicySeparatesDevelopmentAndLegacyMaintenance()
    {
        $content = $this->readProjectFile('SUPPORT.md');

        $this->assertMatchesPattern('/EvolvePHP 2 reports.*proposed changes.*target `2\.x`|EvolvePHP 2.*proposed changes.*reports.*`2\.x`/is', $content);
        $this->assertMatchesPattern('/EvolvePHP 1 legacy maintenance.*`master`.*explicitly approved|explicitly approved.*EvolvePHP 1 legacy maintenance.*`master`/is', $content);
        $this->assertMatchesPattern('/`master` remains preserved|master.*remains preserved/is', $content);
        $this->assertMatchesPattern('/new EvolvePHP 2 feature development.*does not target `?master`?|does not target `?master`?.*new EvolvePHP 2 feature development/is', $content);
        $this->assertMatchesPattern('/EvolvePHP 2.*under development.*not production-ready|not production-ready.*EvolvePHP 2.*under development/is', $content);
    }

    public function testCanonicalDocumentationKeepsStableCutoverDeferred()
    {
        $content = $this->readProjectFile('README.md') . "\n" . $this->readProjectFile('CHANGELOG.md');

        $this->assertMatchesPattern('/`?master`?.*not been renamed or deleted|not been renamed or deleted.*`?master`?/is', $content);
        $this->assertMatchesPattern('/no `?main`? branch has been created|`?main`? branch has not been created/is', $content);
        $this->assertMatchesPattern('/no `?1\.x`? branch has been created|`?1\.x`? branch has not been created/is', $content);
        $this->assertMatchesPattern('/stable-release.*(?:rename|promotion).*deferred|deferred.*stable-release.*(?:rename|promotion)/is', $content);
        $this->assertMatchesPattern('/Phase 2\.7.*does not replace.*legacy history|legacy history.*not.*replaced.*Phase 2\.7/is', $content);
    }

    public function testStageAEvidenceBoundaryIsDocumented()
    {
        $content = $this->readProjectFile('README.md') . "\n" . $this->readProjectFile('CHANGELOG.md');

        $this->assertMatchesPattern('/Phase 2\.7A.*repository-owned branch-governance policy|repository-owned branch-governance policy.*Phase 2\.7A/is', $content);
        $this->assertMatchesPattern('/live default-branch.*ruleset.*pending external GitHub evidence|pending external GitHub evidence.*live default-branch.*ruleset/is', $content);
        $this->assertDoesNotMatchPattern($this->unsupportedDefaultBranchClaimPattern(), $content);
        $this->assertDoesNotMatchPattern('/branch protection is active|rulesets? (?:is|are) active|required checks (?:are|have been) enforced/i', $content);
        $this->assertDoesNotMatchPattern('/master (?:was|has been) (?:renamed|deleted|replaced)/i', $content);
    }

    public function testChangelogRecordsPolicyFoundationWithoutExternalSettingChange()
    {
        $content = $this->readProjectFile('CHANGELOG.md');

        $this->assertMatchesPattern('/Phase 2\.7A/i', $content);
        $this->assertMatchesPattern('/branch-governance policy/i', $content);
        $this->assertMatchesPattern('/EvolvePHP 2 development on `2\.x`/i', $content);
        $this->assertMatchesPattern('/EvolvePHP 1 maintenance on `master`/i', $content);
        $this->assertMatchesPattern('/later default-branch and ruleset transition/i', $content);
        $this->assertDoesNotMatchPattern($this->unsupportedDefaultBranchClaimPattern(), $content);
        $this->assertDoesNotMatchPattern('/branch protection is active|rulesets? (?:is|are) active|required checks (?:are|have been) enforced/i', $content);
    }

    private function unsupportedDefaultBranchClaimPattern()
    {
        $branch = '2' . '\.x';

        return '/' . $branch . ' is (?:now |already )?the (?:GitHub )?default branch|default branch (?:is|changed to) `?' . $branch . '/i';
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

    private function assertDoesNotMatchPattern($pattern, $content)
    {
        $this->assertSame(0, preg_match($pattern, $content), 'Failed asserting that content does not match ' . $pattern);
    }
}
