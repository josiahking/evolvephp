<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2RepositoryGovernanceTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testReadmeDocumentsFinalBranchIdentity()
    {
        $content = $this->readProjectFile('README.md');

        $this->assertMatchesPattern('/2\.x.*designated EvolvePHP 2 development branch|designated EvolvePHP 2 development branch.*2\.x/is', $content);
        $this->assertMatchesPattern('/2\.x.*GitHub default branch|GitHub default branch.*2\.x/is', $content);
        $this->assertMatchesPattern('/master.*preserved EvolvePHP 1 legacy branch|preserved EvolvePHP 1 legacy branch.*master/is', $content);
        $this->assertMatchesPattern('/EvolvePHP 2 changes.*must not target.*master|must not target.*master.*EvolvePHP 2 changes/is', $content);
        $this->assertMatchesPattern('/repository rulesets?.*protect.*branch lines|branch lines.*protect.*repository rulesets?/is', $content);
        $this->assertDoesNotMatchPattern($this->staleTransitionPattern(), $content);
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

    public function testFinalGovernanceRulesetsAreDocumented()
    {
        $content = $this->readProjectFile('README.md') . "\n" . $this->readProjectFile('CHANGELOG.md');

        $this->assertMatchesPattern('/Phase 2\.7B.*completed.*external governance transition|external governance transition.*completed.*Phase 2\.7B/is', $content);
        $this->assertMatchesPattern('/repository rulesets? (?:are )?active|active repository rulesets?/i', $content);
        $this->assertMatchesPattern('/master.*pull request.*deletion.*force-push|master.*force-push.*deletion.*pull request/is', $content);
        $this->assertMatchesPattern('/2\.x.*pull request.*deletion.*force-push.*required CI status checks.*strict|2\.x.*strict.*required CI status checks.*force-push.*deletion.*pull request/is', $content);
        $this->assertMatchesPattern('/required approvals.*zero|zero.*required approvals/i', $content);
        $this->assertMatchesPattern('/conversation resolution.*required|required.*conversation resolution/i', $content);
        $this->assertMatchesPattern('/no bypass actors|bypass actors.*none/i', $content);
        $this->assertMatchesPattern('/Policy \(PHP 8\.4\)/', $content);
        $this->assertMatchesPattern('/Workspace quality \(PHP 8\.4\)/', $content);
        $this->assertMatchesPattern('/Workspace quality \(PHP 8\.5\)/', $content);
        $this->assertDoesNotMatchPattern($this->staleTransitionPattern(), $content);
        $this->assertDoesNotMatchPattern('/classic branch protection|branch protection is active/i', $content);
        $this->assertDoesNotMatchPattern('/master (?:was|has been) (?:renamed|deleted|replaced)/i', $content);
    }

    public function testChangelogRecordsFinalGovernanceEvidence()
    {
        $content = $this->readProjectFile('CHANGELOG.md');

        $this->assertMatchesPattern('/Phase 2\.7A/i', $content);
        $this->assertMatchesPattern('/Phase 2\.7B/i', $content);
        $this->assertMatchesPattern('/GitHub default branch.*`2\.x`|`2\.x`.*GitHub default branch/i', $content);
        $this->assertMatchesPattern('/repository rulesets?.*`master`.*`2\.x`|`master`.*`2\.x`.*repository rulesets?/is', $content);
        $this->assertMatchesPattern('/preserved `master`.*EvolvePHP 1 legacy line|EvolvePHP 1 legacy line.*preserved `master`/i', $content);
        $this->assertMatchesPattern('/PR-based change.*both branches|both branches.*PR-based change/i', $content);
        $this->assertMatchesPattern('/block(?:s|ed)? deletion.*force pushes|force pushes.*block(?:s|ed)? deletion/i', $content);
        $this->assertMatchesPattern('/strict.*up-to-date required status checks|up-to-date.*strict.*required status checks/i', $content);
        $this->assertMatchesPattern('/Policy \(PHP 8\.4\).*Workspace quality \(PHP 8\.4\).*Workspace quality \(PHP 8\.5\)/is', $content);
        $this->assertMatchesPattern('/no branch rename or deletion|no branch was renamed or deleted/i', $content);
        $this->assertDoesNotMatchPattern('/classic branch protection|branch protection is active/i', $content);
    }

    private function staleTransitionPattern()
    {
        return '/current GitHub default.*master|default branch remains.*master|ruleset.*pending|default-branch.*pending|pending external GitHub evidence|live.*transition.*pending/i';
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
