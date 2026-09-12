<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2AlphaDocumentationCheckpointTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testAlphaDocumentationFilesExist(): void
    {
        foreach ($this->alphaDocumentationFiles() as $path) {
            $this->assertFileExists($this->projectPath($path), $path . ' should exist.');
        }

        $this->assertFileExists($this->projectPath('docs/releases/2.0.0-alpha.1.md'));
    }

    public function testRootReadmeLinksTheAlphaDocumentationSet(): void
    {
        $content = $this->readProjectFile('README.md');

        foreach (array_merge($this->alphaDocumentationFiles(), array('docs/releases/2.0.0-alpha.1.md')) as $path) {
            $this->assertStringContainsString($path, $content);
        }

        $this->assertStringContainsString('CONTRIBUTING.md', $content);
    }

    public function testContributingGuideDocumentsPublicContributionBoundaries(): void
    {
        $content = $this->readProjectFile('CONTRIBUTING.md');

        $this->assertStringContainsString('DEVELOPMENT.md', $content);
        $this->assertStringContainsString('AGENTS.md', $content);
        $this->assertStringContainsString('SECURITY.md', $content);
        $this->assertStringContainsString('LICENSE.md', $content);

        $this->assertMatchesPattern('/vulnerabilit(?:y|ies).*SECURITY\.md|SECURITY\.md.*vulnerabilit(?:y|ies)/is', $content);
        $this->assertMatchesPattern('/task-specific branches|task branches|task-branch/is', $content);
        $this->assertMatchesPattern('/do not work directly on `2\.x`|do not work directly on.*2\.x/is', $content);
        $this->assertMatchesPattern('/do not work directly on `master`|do not work directly on.*master/is', $content);
        $this->assertMatchesPattern('/master.*EvolvePHP 1|EvolvePHP 1.*master/is', $content);
        $this->assertMatchesPattern('/small.*reviewable|reviewable.*small/is', $content);
        $this->assertMatchesPattern('/scope discipline|limited to the requested problem|limited.*scope/is', $content);
        $this->assertMatchesPattern('/RED\s*->\s*GREEN\s*->\s*REFACTOR/i', $content);
        $this->assertMatchesPattern('/policy or validation tests|validation tests.*policy/is', $content);
        $this->assertMatchesPattern('/Public API.*deliberate review|deliberate review.*Public API/is', $content);
        $this->assertMatchesPattern('/RFCs?.*not.*contradicted|not.*contradicted.*RFCs?/is', $content);
        $this->assertMatchesPattern('/package.*dependency.*boundaries|dependency.*boundaries.*package/is', $content);
        $this->assertMatchesPattern('/dependencies.*without explaining|dependency changes.*without explaining|without explaining.*dependencies/is', $content);
        $this->assertMatchesPattern('/compatibility.*licen[cs]e|licen[cs]e.*compatibility/is', $content);
        $this->assertMatchesPattern('/implemented behavior|current limitations/i', $content);
        $this->assertMatchesPattern('/focused checks.*broader quality|broader quality.*focused checks/is', $content);
        $this->assertMatchesPattern('/Pull requests?.*scope.*tests.*risks.*deferred|scope.*tests.*risks.*deferred/is', $content);
    }

    public function testGettingStartedStatesPhpAndPublicationBoundaries(): void
    {
        $content = $this->readProjectFile('docs/alpha/getting-started.md');

        $this->assertMatchesPattern('/PHP 8\.4/i', $content);
        $this->assertMatchesPattern('/PHP 8\.4.*PHP 8\.5|PHP 8\.5.*PHP 8\.4/is', $content);
        $this->assertMatchesPattern('/source-preview|source preview/i', $content);
        $this->assertMatchesPattern('/not yet independently published|packages.*not.*published/is', $content);
        $this->assertMatchesPattern('/public.*create-project.*not yet|create-project.*not yet.*public/is', $content);
        $this->assertMatchesPattern('/skeleton.*application template/is', $content);
        $this->assertMatchesPattern('/doctor/i', $content);
        $this->assertMatchesPattern('/route:list/i', $content);
        $this->assertMatchesPattern('/routes?.*empty|empty.*routes?/is', $content);
        $this->assertStringContainsString('application-foundations.md', $content);
        $this->assertStringContainsString('status-and-limitations.md', $content);

        $this->assertDoesNotMatchPattern('/composer\s+create-project\s+evolvephp\/skeleton/i', $content);
        $this->assertDoesNotMatchPattern('/composer\s+require\s+evolvephp\//i', $content);
    }

    public function testApplicationFoundationsDocumentsImplementedAreasAndWebRuntimeLimit(): void
    {
        $content = $this->readProjectFile('docs/alpha/application-foundations.md');

        foreach (array(
            '/configuration/i',
            '/service registry|container/i',
            '/application.*execution.*transient|transient.*execution.*application/is',
            '/execution scopes?.*reset|reset.*execution scopes?/is',
            '/execution orchestration/i',
            '/middleware/i',
            '/routing|routed dispatch/i',
            '/HTTP kernel/i',
            '/response.*resolution|response-resolution/i',
            '/response.*emission/i',
            '/component identity/i',
            '/module.*plugin|plugin.*module/is',
            '/dependency.*capability graph|capability.*dependency graph/is',
            '/restricted service registration/i',
            '/component lifecycle/i',
            '/Composer plugin discovery/i',
            '/application-controlled enablement/i',
            '/CLI command/i',
            '/doctor/i',
            '/route:list/i',
            '/generators?/i',
        ) as $pattern) {
            $this->assertMatchesPattern($pattern, $content);
        }

        $this->assertMatchesPattern('/concrete SAPI.*deferred|SAPI.*runtime.*deferred|web.*runtime.*deferred/is', $content);
        $this->assertDoesNotMatchPattern('/php\s+-S\s+localhost/i', $content);
    }

    public function testModernizationGuideDocumentsBoundedBridgePaths(): void
    {
        $content = $this->readProjectFile('docs/alpha/modernization-and-bridge.md');

        foreach (array(
            '/Audit/i',
            '/read-only/i',
            '/explicit target root/i',
            '/Composer.*lockfile.*PHP source|PHP source.*lockfile.*Composer/is',
            '/does not execute target code/i',
            '/Adoption planning/i',
            '/route ownership/i',
            '/data ownership/i',
            '/rollback evidence/i',
            '/acceptance criteria/i',
            '/Embedded Bridge/i',
            '/PSR/i',
            '/Laravel/i',
            '/Symfony/i',
            '/Remote.*sidecar|sidecar.*Remote/is',
            '/HTTP JSON protocol|JSON.*protocol/i',
            '/Legacy PHP/i',
            '/PHP 7\.4/i',
        ) as $pattern) {
            $this->assertMatchesPattern($pattern, $content);
        }

        $this->assertMatchesPattern('/one authoritative owner|one owner/is', $content);
        $this->assertMatchesPattern('/no automatic migration|does not.*automatic.*migration/is', $content);
        $this->assertMatchesPattern('/no automatic.*cutover|does not.*cutover/is', $content);
        $this->assertMatchesPattern('/no shared sessions|sessions.*not.*shared/is', $content);
        $this->assertMatchesPattern('/no distributed transaction|distributed transaction.*not/is', $content);
        $this->assertDoesNotMatchPattern('/provides automatic migration|performs automatic migration/i', $content);
        $this->assertDoesNotMatchPattern('/provides automatic cutover|performs automatic cutover/i', $content);
    }

    public function testStatusAndLimitationsDocumentsAlphaBoundaries(): void
    {
        $content = $this->readProjectFile('docs/alpha/status-and-limitations.md');

        foreach (array('Implemented', 'Experimental', 'Deferred', 'Unsupported') as $heading) {
            $this->assertMatchesPattern('/^## .*' . preg_quote($heading, '/') . '/mi', $content);
        }

        $this->assertMatchesPattern('/pre-release|Alpha/i', $content);
        $this->assertMatchesPattern('/not production-ready|not recommended for production/i', $content);
        $this->assertMatchesPattern('/breaking changes/i', $content);
        $this->assertMatchesPattern('/PHP 8\.4/i', $content);
        $this->assertMatchesPattern('/PHP 8\.4.*PHP 8\.5|PHP 8\.5.*PHP 8\.4/is', $content);
        $this->assertMatchesPattern('/PHP 7\.4.*legacy.*client|legacy.*client.*PHP 7\.4/is', $content);
        $this->assertMatchesPattern('/does not lower.*PHP 8\.4|PHP 8\.4.*does not lower/is', $content);
        $this->assertMatchesPattern('/not yet independently published|packages.*not.*published/is', $content);
        $this->assertMatchesPattern('/Packagist.*not.*available|no public Packagist/is', $content);
        $this->assertMatchesPattern('/SAPI|web runtime/i', $content);
        $this->assertMatchesPattern('/no automatic migration|automatic migration.*not/is', $content);
        $this->assertMatchesPattern('/no LTS|not.*LTS/i', $content);
        $this->assertMatchesPattern('/no production support SLA|production support SLA.*not/i', $content);
    }

    public function testDraftReleaseNoteIsNotReleasedAndAvoidsFinalPublicationClaims(): void
    {
        $content = $this->readProjectFile('docs/releases/2.0.0-alpha.1.md');

        $this->assertMatchesPattern('/DRAFT.*NOT RELEASED|NOT RELEASED.*DRAFT/is', substr($content, 0, 300));

        foreach (array(
            '/PHP 8\.4/i',
            '/PHP 8\.4.*PHP 8\.5|PHP 8\.5.*PHP 8\.4/is',
            '/experimental|Alpha/i',
            '/not yet independently published|packages.*not.*published/is',
            '/not production-ready|no production-readiness claim/i',
        ) as $pattern) {
            $this->assertMatchesPattern($pattern, $content);
        }

        $this->assertDoesNotMatchPattern('/GitHub release/i', $content);
        $this->assertDoesNotMatchPattern('/Packagist.*available|available.*Packagist/i', $content);
        $this->assertDoesNotMatchPattern('/final tagged commit|tagged commit.*[0-9a-f]{7,40}/i', $content);
        $this->assertDoesNotMatchPattern('/Released on|Release date:/i', $content);
    }

    public function testSupportAndChangelogContainAlphaPublicBoundaries(): void
    {
        $support = $this->readProjectFile('SUPPORT.md');
        $changelog = $this->readProjectFile('CHANGELOG.md');

        $this->assertMatchesPattern('/best-effort.*pre-release|pre-release.*best-effort/is', $support);
        $this->assertMatchesPattern('/not.*LTS|LTS.*not/is', $support);
        $this->assertMatchesPattern('/experimental APIs?.*change|change.*experimental APIs?/is', $support);
        $this->assertMatchesPattern('/PHP 8\.4/i', $support);
        $this->assertMatchesPattern('/PHP 8\.4.*PHP 8\.5|PHP 8\.5.*PHP 8\.4/is', $support);
        $this->assertMatchesPattern('/PHP 7\.4.*legacy.*client|legacy.*client.*PHP 7\.4/is', $support);
        $this->assertMatchesPattern('/SECURITY\.md/i', $support);

        $this->assertMatchesPattern('/Alpha documentation/i', $changelog);
        $this->assertDoesNotMatchPattern('/##\s+2\.0\.0-alpha\.1/i', $changelog);
    }

    public function testPublicAlphaDocumentationAvoidsInternalWorkflowTerms(): void
    {
        foreach ($this->publicCheckpointDocumentationFiles() as $path) {
            $content = $this->readProjectFile($path);

            foreach (array(
                '/Phase 7\.16/i',
                '/assistant review/i',
                '/Notion gate/i',
                '/private workflow/i',
                '/coding-agent/i',
                '/COMMITTED-REF GATE/i',
            ) as $pattern) {
                $this->assertDoesNotMatchPattern($pattern, $content, $path);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function alphaDocumentationFiles(): array
    {
        return array(
            'docs/alpha/getting-started.md',
            'docs/alpha/application-foundations.md',
            'docs/alpha/modernization-and-bridge.md',
            'docs/alpha/status-and-limitations.md',
        );
    }

    /**
     * @return list<string>
     */
    private function publicCheckpointDocumentationFiles(): array
    {
        return array_merge(
            array('README.md', 'SUPPORT.md', 'CHANGELOG.md', 'skeleton/README.md'),
            $this->alphaDocumentationFiles(),
            array('docs/releases/2.0.0-alpha.1.md', 'CONTRIBUTING.md')
        );
    }

    private function projectPath(string $path): string
    {
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function readProjectFile(string $path): string
    {
        $fullPath = $this->projectPath($path);
        $this->assertFileExists($fullPath, $path . ' should exist before it is read.');

        $content = file_get_contents($fullPath);
        $this->assertNotFalse($content, $path . ' should be readable.');

        return $content;
    }

    private function assertMatchesPattern(string $pattern, string $content, string $message = ''): void
    {
        $this->assertSame(1, preg_match($pattern, $content), $message !== '' ? $message : 'Failed asserting that content matches ' . $pattern);
    }

    private function assertDoesNotMatchPattern(string $pattern, string $content, string $message = ''): void
    {
        $this->assertSame(0, preg_match($pattern, $content), $message !== '' ? $message : 'Failed asserting that content does not match ' . $pattern);
    }
}
