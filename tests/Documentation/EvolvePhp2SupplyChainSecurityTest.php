<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2SupplyChainSecurityTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testDependabotConfigurationDeclaresRepositoryOwnedVersionUpdates(): void
    {
        $content = $this->readProjectFile('.github/dependabot.yml');

        $this->assertMatchesPattern('/^version:\s*2\s*$/m', $content);
        $this->assertMatchesPattern('/package-ecosystem:\s*"composer"|package-ecosystem:\s*composer/', $content);
        $this->assertMatchesPattern('/directory:\s*"\/"|directory:\s*\//', $content);
        $this->assertMatchesPattern('/package-ecosystem:\s*"github-actions"|package-ecosystem:\s*github-actions/', $content);
        $this->assertMatchesPattern('/directory:\s*"\/"|directory:\s*\/\s*$/m', $content);
        $this->assertSame(2, preg_match_all('/interval:\s*"weekly"|interval:\s*weekly/', $content, $matches));

        foreach (array(
            '/registr(?:y|ies):/i',
            '/token|password|secret/i',
            '/auto.?merge/i',
            '/target-branch:/i',
        ) as $pattern) {
            $this->assertDoesNotMatchPattern($pattern, $content);
        }

        foreach ($this->evolvePathPackages() as $package) {
            $this->assertMatchesPattern('/"' . preg_quote($package, '/') . '"|\b' . preg_quote($package, '/') . '\b/', $content);
        }
    }

    public function testWorkspaceComposerDeclaresCanonicalSupplyChainScripts(): void
    {
        $manifest = $this->readJsonFile('composer.json');

        $this->assertArrayHasKey('scripts', $manifest);
        $this->assertArrayHasKey('security:audit', $manifest['scripts']);
        $this->assertArrayHasKey('licenses:check', $manifest['scripts']);
        $this->assertArrayHasKey('supply-chain', $manifest['scripts']);
        $this->assertSame('@composer audit --locked --abandoned=fail', $manifest['scripts']['security:audit']);
        $this->assertSame('@php tools/check-licenses.php', $manifest['scripts']['licenses:check']);
        $this->assertSame(array('@security:audit', '@licenses:check'), $manifest['scripts']['supply-chain']);
        $this->assertSame(array('@architecture', '@analyse', '@style:check', '@test'), $manifest['scripts']['quality']);
    }

    public function testLicenceCheckerEnforcesLockedProductionAndDevelopmentPolicy(): void
    {
        $content = $this->readProjectFile('tools/check-licenses.php');

        foreach (array('MIT', 'BSD-3-Clause', 'Apache-2.0') as $license) {
            $this->assertMatchesPattern('/[\'"]' . preg_quote($license, '/') . '[\'"]/', $content);
        }

        foreach (array(
            '/packages-dev/',
            '/packages/',
            '/composer\.lock/',
            '/json_decode/',
            '/license/',
            '/version/',
            '/exit\(1\)|return\s+1/',
            '/missing/i',
            '/unapproved|not approved/i',
        ) as $pattern) {
            $this->assertMatchesPattern($pattern, $content);
        }

        foreach (array(
            '/curl_|file_get_contents\(\s*[\'"]https?:/i',
            '/jetbrains\/phpstorm-stubs/i',
            '/ignore|allow-failure|bypass|exception/i',
            '/GPL-3\.0-only|MPL-2\.0|proprietary/',
        ) as $pattern) {
            $this->assertDoesNotMatchPattern($pattern, $content);
        }
    }

    public function testPolicyWorkflowRunsSupplyChainGateWithoutRenamingRequiredJobs(): void
    {
        $workflow = $this->readProjectFile('.github/workflows/quality.yml');
        $policyJob = $this->extractJob($workflow, 'policy');

        $this->assertMatchesPattern('/name:\s*Policy \(PHP 8\.4\)/', $policyJob);
        $this->assertMatchesPattern('/name:\s*Workspace quality \(PHP \$\{\{ matrix\.php \}\}\)/', $workflow);
        $this->assertStringContainsString('composer supply-chain', $policyJob);
        $this->assertSame(1, preg_match_all('/composer supply-chain/', $workflow, $matches));
        $this->assertBefore(
            'composer install --no-interaction --no-progress --prefer-dist',
            'composer supply-chain',
            $policyJob
        );
        $this->assertBefore(
            'composer supply-chain',
            'php vendor/bin/phpunit --configuration phpunit.xml.dist tests/Architecture tests/Documentation',
            $policyJob
        );
        $this->assertMatchesPattern('/^permissions:\s*\R\s{2}contents:\s*read\s*$/m', $workflow);
        $this->assertDoesNotMatchPattern('/composer supply-chain/', $this->extractJob($workflow, 'workspace-quality'));
    }

    public function testDocumentationRecordsSupplyChainSecurityBoundaries(): void
    {
        $readme = $this->readProjectFile('DEVELOPMENT.md');
        $changelog = $this->readProjectFile('CHANGELOG.md');

        foreach (array(
            '/## Supply-Chain Security/',
            '/composer security:audit/',
            '/composer licenses:check/',
            '/composer supply-chain/',
            '/committed lockfile|lockfile.*committed/i',
            '/abandoned packages fail|fail.*abandoned packages/i',
            '/require-dev.*included|development dependencies.*included/i',
            '/production and development.*packages|packages.*production and development/i',
            '/MIT/',
            '/BSD-3-Clause/',
            '/Apache-2\.0/',
            '/engineering.*policy|policy.*engineering/i',
            '/not legal advice|not a legal opinion/i',
            '/unknown|new licence/i',
            '/deliberate review/i',
            '/no advisory suppression|advisory suppression.*no/i',
            '/network access|remote advisory/i',
            '/quality.*distinct|distinct.*quality/i',
            '/Dependabot.*\//i',
            '/GitHub Actions/',
            '/GitHub settings/i',
            '/vulnerability alerts|security updates/i',
            '/jetbrains\/phpstorm-stubs/i',
            '/distribution|attribution/i',
        ) as $pattern) {
            $this->assertMatchesPattern($pattern, $readme);
        }

        $this->assertMatchesPattern('/Phase 2\.9A/i', $changelog);
        $this->assertMatchesPattern('/supply-chain/i', $changelog);
    }

    private function extractJob($workflow, $job)
    {
        $this->assertSame(1, preg_match('/^jobs:\s*\R(?P<jobs>.*)\z/ms', $workflow, $jobsMatch), 'Workflow jobs block should exist.');
        $pattern = '/^\s{2}' . preg_quote($job, '/') . ':\s*\R(?P<job>.*?)(?=^\s{2}[a-zA-Z0-9_-]+:\s*|\z)/ms';

        $this->assertSame(1, preg_match($pattern, $jobsMatch['jobs'], $matches), 'Missing job: ' . $job);

        return $matches['job'];
    }

    private function assertBefore($first, $second, $content)
    {
        $firstPosition = strpos($content, $first);
        $secondPosition = strpos($content, $second);

        $this->assertNotFalse($firstPosition, 'Expected to find: ' . $first);
        $this->assertNotFalse($secondPosition, 'Expected to find: ' . $second);
        $this->assertLessThan($secondPosition, $firstPosition, $first . ' should appear before ' . $second);
    }

    private function evolvePathPackages()
    {
        return array(
            'evolvephp/contracts',
            'evolvephp/core',
            'evolvephp/http',
            'evolvephp/module',
            'evolvephp/plugin',
            'evolvephp/testing',
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
