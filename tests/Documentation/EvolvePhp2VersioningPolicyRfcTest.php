<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2VersioningPolicyRfcTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testRfc0003ExistsWithAcceptedMetadata(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0003-php-versioning-compatibility-and-release-policy.md');

        $this->assertMatchesPattern('/#\s*RFC\s+0003:\s*PHP Versioning, Compatibility and Release Policy/i', $content);
        $this->assertMatchesPattern('/Status:\s*Accepted/i', $content);
        $this->assertMatchesPattern('/Target release:\s*EvolvePHP 2\.0/i', $content);
        $this->assertMatchesPattern('/Decision type:\s*Compatibility, versioning and release governance/i', $content);
        $this->assertMatchesPattern('/Depends on:\s*RFC 0001,\s*RFC 0002/i', $content);
    }

    public function testPhpPolicyAndComposerConstraintAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0003-php-versioning-compatibility-and-release-policy.md');

        $this->assertMatchesPattern('/Minimum PHP version:\s*8\.4/i', $content);
        $this->assertMatchesPattern('/Initially tested PHP versions:\s*8\.4 and 8\.5/i', $content);
        $this->assertMatchesPattern('/PHP 8\.3 or earlier/i', $content);
        $this->assertMatchesPattern('/not establish EvolvePHP 2 compatibility/i', $content);
        $this->assertMatchesPattern('/"php":\s*"\^8\.4"/i', $content);
        $this->assertMatchesPattern('/Composer accepting a future PHP version does not by itself mean/i', $content);
        $this->assertMatchesPattern('/Implementation of the constraint belongs to a later/i', $content);
        $this->assertMatchesPattern('/config\.platform\.php.*must not.*replace real test execution/is', $content);
        $this->assertMatchesPattern('/CI must use real PHP runtimes/i', $content);
    }

    public function testPhpMinorAdditionAndRemovalPoliciesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0003-php-versioning-compatibility-and-release-policy.md');

        $this->assertMatchesPattern('/Adding Support For A New PHP Minor/i', $content);
        $this->assertMatchesPattern('/CI executes the complete applicable test suite/i', $content);
        $this->assertMatchesPattern('/Support must not be claimed solely from syntax linting/i', $content);
        $this->assertMatchesPattern('/Removing Support For A PHP Minor/i', $content);
        $this->assertMatchesPattern('/Dropping a supported PHP minor is normally a breaking change/i', $content);
        $this->assertMatchesPattern('/must not be removed in a patch release/i', $content);
    }

    public function testSemanticVersioningAndPrereleaseStagesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0003-php-versioning-compatibility-and-release-policy.md');

        $this->assertMatchesPattern('/Semantic Versioning/i', $content);
        $this->assertMatchesPattern('/MAJOR\.MINOR\.PATCH/i', $content);
        $this->assertMatchesPattern('/Major Release/i', $content);
        $this->assertMatchesPattern('/Minor Release/i', $content);
        $this->assertMatchesPattern('/Patch Release/i', $content);
        $this->assertMatchesPattern('/Alpha/i', $content);
        $this->assertMatchesPattern('/Beta/i', $content);
        $this->assertMatchesPattern('/Release Candidate/i', $content);
        $this->assertMatchesPattern('/Stable/i', $content);
        $this->assertMatchesPattern('/2\.0\.0-alpha\.1/i', $content);
        $this->assertMatchesPattern('/2\.0\.0-beta\.1/i', $content);
        $this->assertMatchesPattern('/2\.0\.0-rc\.1/i', $content);
        $this->assertMatchesPattern('/Published versions are immutable/i', $content);
        $this->assertMatchesPattern('/Git tags and Composer package versions must match/i', $content);
    }

    public function testCompatibilityDeprecationAndApiPoliciesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0003-php-versioning-compatibility-and-release-policy.md');

        $this->assertMatchesPattern('/Stable public APIs/i', $content);
        $this->assertMatchesPattern('/Experimental APIs/i', $content);
        $this->assertMatchesPattern('/Internal APIs/i', $content);
        $this->assertMatchesPattern('/Deprecation Policy/i', $content);
        $this->assertMatchesPattern('/Document the replacement/i', $content);
        $this->assertMatchesPattern('/migration guidance/i', $content);
        $this->assertMatchesPattern('/Do not remove a stable deprecated API in a patch release/i', $content);
        $this->assertMatchesPattern('/Deprecation without a usable migration path is incomplete/i', $content);
    }

    public function testSecuritySupportBranchesTagsAndReadinessAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0003-php-versioning-compatibility-and-release-policy.md');

        $this->assertMatchesPattern('/Security Release Policy/i', $content);
        $this->assertMatchesPattern('/does not invent|does not promise a guaranteed response time|service-level agreement/is', $content);
        $this->assertMatchesPattern('/Do not declare EvolvePHP 2\.0 LTS|does not promise LTS/is', $content);
        $this->assertMatchesPattern('/`2\.x`/i', $content);
        $this->assertMatchesPattern('/Main development and integration branch for EvolvePHP 2/i', $content);
        $this->assertMatchesPattern('/`master`/i', $content);
        $this->assertMatchesPattern('/EvolvePHP 1 legacy line/i', $content);
        $this->assertMatchesPattern('/Do not create maintenance branches prematurely/i', $content);
        $this->assertMatchesPattern('/Published tags are immutable/i', $content);
        $this->assertMatchesPattern('/stable EvolvePHP release requires/i', $content);
    }

    public function testDocumentationDependencyCiLockfileAndRollbackPoliciesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0003-php-versioning-compatibility-and-release-policy.md');

        $this->assertMatchesPattern('/Changelog Policy/i', $content);
        $this->assertMatchesPattern('/Release Documentation/i', $content);
        $this->assertMatchesPattern('/Dependency Compatibility/i', $content);
        $this->assertMatchesPattern('/Lock-File Policy/i', $content);
        $this->assertMatchesPattern('/Continuous-Integration Policy/i', $content);
        $this->assertMatchesPattern('/PHP 8\.4/i', $content);
        $this->assertMatchesPattern('/PHP 8\.5/i', $content);
        $this->assertMatchesPattern('/Officially supported PHP versions must not be allowed to fail/i', $content);
        $this->assertMatchesPattern('/Release Failure And Rollback/i', $content);
    }

    public function testGovernanceSectionsIndexAndChangelogAreUpdated(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0003-php-versioning-compatibility-and-release-policy.md');
        $index = $this->readProjectFile('docs/rfcs/README.md');
        $changelog = $this->readProjectFile('CHANGELOG.md');

        $this->assertMatchesPattern('/Consequences and Tradeoffs/i', $content);
        $this->assertMatchesPattern('/Alternatives Considered/i', $content);
        $this->assertMatchesPattern('/Governance/i', $content);
        $this->assertMatchesPattern('/0003-php-versioning-compatibility-and-release-policy\.md/i', $index);
        $this->assertMatchesPattern('/RFC 0003/i', $index);
        $this->assertMatchesPattern('/RFC 0003 defines compatibility, versioning and release policy/i', $index);
        $this->assertMatchesPattern('/RFC 0003/i', $changelog);
        $this->assertMatchesPattern('/PHP Versioning, Compatibility and Release Policy/i', $changelog);
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
