<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2ContinuousIntegrationTest extends TestCase
{
    private string $root;
    private string $workflowPath;
    private string $workflow;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->workflowPath = $this->projectPath('.github/workflows/quality.yml');

        $this->assertFileExists($this->workflowPath, 'The canonical EvolvePHP 2 quality workflow should exist.');

        $workflow = file_get_contents($this->workflowPath);
        $this->assertNotFalse($workflow, 'The canonical EvolvePHP 2 quality workflow should be readable.');

        $this->workflow = $workflow;
    }

    public function testCanonicalWorkflowNameAndTriggersTargetOnlyEvolvePhp2(): void
    {
        $this->assertMatchesPattern('/^name:\s*EvolvePHP 2 Quality\s*$/m', $this->workflow);
        $this->assertMatchesPattern('/^on:\s*$/m', $this->workflow);
        $this->assertMatchesPattern('/^\s{2}pull_request:\s*\R\s{4}branches:\s*\R\s{6}- 2\.x\s*$/m', $this->workflow);
        $this->assertMatchesPattern('/^\s{2}push:\s*\R\s{4}branches:\s*\R\s{6}- 2\.x\s*$/m', $this->workflow);
        $this->assertMatchesPattern('/^\s{2}workflow_dispatch:\s*$/m', $this->workflow);
        $this->assertDoesNotMatchPattern('/^\s*-\s*master\s*$/m', $this->workflow);
        $this->assertDoesNotMatchPattern('/pull_request_target/', $this->workflow);
        $this->assertDoesNotMatchPattern('/paths(?:-ignore)?:/', $this->workflow);
        $this->assertDoesNotMatchPattern('/tags(?:-ignore)?:/', $this->workflow);
    }

    public function testWorkflowUsesLeastPrivilegePermissionsAndCancelsSupersededRuns(): void
    {
        $this->assertMatchesPattern('/^permissions:\s*\R\s{2}contents:\s*read\s*$/m', $this->workflow);
        $this->assertSame(1, preg_match_all('/^\s{2}[a-z-]+:\s*(?:read|write|none)\s*$/m', $this->extractTopLevelBlock('permissions'), $matches));
        $this->assertDoesNotMatchPattern('/\bwrite\b/', $this->extractTopLevelBlock('permissions'));
        $this->assertMatchesPattern('/^concurrency:\s*$/m', $this->workflow);
        $this->assertMatchesPattern('/^\s{2}group:\s*evolvephp-2-quality-\$\{\{ github\.workflow \}\}-\$\{\{ github\.event\.pull_request\.number \|\| github\.ref \}\}\s*$/m', $this->workflow);
        $this->assertMatchesPattern('/^\s{2}cancel-in-progress:\s*true\s*$/m', $this->workflow);
    }

    public function testWorkflowUsesOnlyApprovedUbuntuRunnerAndPhpMatrix(): void
    {
        $this->assertSame(3, preg_match_all('/^\s{4}runs-on:\s*ubuntu-24\.04\s*$/m', $this->workflow, $matches));
        $this->assertDoesNotMatchPattern('/ubuntu-latest|windows-|macos-/', $this->workflow);
        $this->assertMatchesPattern('/fail-fast:\s*false/', $this->workflow);
        $this->assertMatchesPattern('/php:\s*\R\s{10}- \'8\.4\'\s*\R\s{10}- \'8\.5\'/m', $this->workflow);
        $this->assertSame(2, preg_match_all('/^\s{10}- \'8\.[45]\'\s*$/m', $this->workflow, $matches));
        $this->assertDoesNotMatchPattern('/nightly|experimental|lowest|highest|latest|8\.6/', $this->workflow);
    }

    public function testWorkflowPinsOnlyReviewedActionReleaseCommits(): void
    {
        $checkoutSha = '3d3c42e5aac5ba805825da76410c181273ba90b1';
        $setupPhpSha = 'f3e473d116dcccaddc5834248c87452386958240';

        $this->assertSame(3, preg_match_all('/uses:\s*actions\/checkout@' . $checkoutSha . '\s+# v7\.0\.1/', $this->workflow, $matches));
        $this->assertSame(3, preg_match_all('/uses:\s*shivammathur\/setup-php@' . $setupPhpSha . '\s+# 2\.37\.2/', $this->workflow, $matches));
        $this->assertSame(6, preg_match_all('/uses:\s*[^@\s]+@[0-9a-f]{40}\s+# (?:v7\.0\.1|2\.37\.2)/', $this->workflow, $matches));
        $this->assertMatchesPattern('/persist-credentials:\s*false/', $this->workflow);
        $this->assertSame(3, preg_match_all('/tools:\s*composer:v2/', $this->workflow, $matches));
        $this->assertSame(3, preg_match_all('/coverage:\s*none/', $this->workflow, $matches));
        $this->assertDoesNotMatchPattern('/actions\/checkout@(?:v[0-9]+|main)|shivammathur\/setup-php@(?:v[0-9]+|main)/', $this->workflow);
        $this->assertDoesNotMatchPattern('/uses:\s*(?!actions\/checkout@|shivammathur\/setup-php@)[^@\s]+@/', $this->workflow);
        $this->assertDoesNotMatchPattern('/@[0-9a-f]{7,39}(?:\s|$)/', $this->workflow);
        $this->assertDoesNotMatchPattern('/actions\/cache/', $this->workflow);
    }

    public function testPolicyJobRunsRootPolicySuitesWithRootPhpUnitOnPhp84(): void
    {
        $job = $this->extractJob('policy');

        $this->assertMatchesPattern('/name:\s*Policy \(PHP 8\.4\)/', $job);
        $this->assertMatchesPattern('/runs-on:\s*ubuntu-24\.04/', $job);
        $this->assertMatchesPattern('/php-version:\s*\'8\.4\'/', $job);
        $this->assertStringContainsString('composer validate --strict --check-lock', $job);
        $this->assertStringContainsString('composer install --no-interaction --no-progress --prefer-dist', $job);
        $this->assertSame(1, substr_count($job, 'composer supply-chain'));
        $this->assertSame(1, substr_count($job, 'composer release:split:validate'));
        $this->assertStringContainsString('php vendor/bin/phpunit --configuration phpunit.xml.dist tests/Architecture tests/Documentation', $job);
        $this->assertStringNotContainsString('release:consumer:validate', $this->workflow);
        $this->assertDoesNotMatchPattern('/--working-dir=workspace|composer --working-dir=\.\s+install/', $job);
        $this->assertDoesNotMatchPattern('/composer update|--ignore-platform-reqs?|config\.platform\.php/', $job);
        $this->assertDoesNotMatchPattern('/phpunit.*(?:core|components|helpers|index\.php|route\.php)/i', $job);
    }

    public function testBenchmarkPolicyJobRunsOnlyIsolatedBenchmarkPolicyChecksOnPhp84(): void
    {
        $job = $this->extractJob('benchmark-policy');

        $this->assertMatchesPattern('/name:\s*Benchmark policy \(PHP 8\.4\)/', $job);
        $this->assertMatchesPattern('/runs-on:\s*ubuntu-24\.04/', $job);
        $this->assertMatchesPattern('/php-version:\s*\'8\.4\'/', $job);
        $this->assertStringContainsString('uses: actions/checkout@', $this->extractStep($job, 'Checkout repository'));
        $this->assertStringContainsString('uses: shivammathur/setup-php@', $this->extractStep($job, 'Set up PHP'));
        $this->assertStringContainsString('composer validate --working-dir=benchmarks --strict --check-lock', $job);
        $this->assertStringContainsString('composer install --working-dir=benchmarks --no-interaction --no-progress --prefer-dist', $job);
        $this->assertStringContainsString('composer --working-dir=benchmarks ci:policy', $job);

        foreach ([
            '/benchmarks\/bin\/comparator-run\.php/',
            '/comparator:run/',
            '/100-sample|--samples(?:=|\s+)100|samples:\s*100|sample(?:s)?(?:=|\s+)100/i',
            '/tools\/(?:release-validation|validate-release|validate-prerelease-consumers)|validate-prerelease-consumers\.php/i',
            '/release:split:validate/',
            '/release:skeleton:validate/',
            '/release:consumer:validate/',
            '/release:validate/',
            '/composer update/',
            '/--ignore-platform-reqs?/',
            '/continue-on-error/',
            '/secrets\./',
            '/actions\/cache/',
            '/cache:/',
            '/deploy(?:ment)?/i',
            '/publish/i',
            '/upload-artifact/',
            '/gh\s+release/i',
        ] as $pattern) {
            $this->assertDoesNotMatchPattern($pattern, $job);
        }
    }

    public function testPolicyCheckoutFetchesCompleteHistoryForReleaseSplitValidation(): void
    {
        $policyCheckout = $this->extractStep($this->extractJob('policy'), 'Checkout repository');
        $benchmarkPolicyCheckout = $this->extractStep($this->extractJob('benchmark-policy'), 'Checkout repository');
        $workspaceQualityCheckout = $this->extractStep($this->extractJob('workspace-quality'), 'Checkout repository');

        $this->assertSame(1, substr_count($this->workflow, 'fetch-depth: 0'));
        $this->assertMatchesPattern('/persist-credentials:\s*false/', $policyCheckout);
        $this->assertMatchesPattern('/fetch-depth:\s*0/', $policyCheckout);
        $this->assertStringContainsString('composer release:split:validate', $this->extractJob('policy'));

        $this->assertMatchesPattern('/persist-credentials:\s*false/', $benchmarkPolicyCheckout);
        $this->assertDoesNotMatchPattern('/fetch-depth:\s*0/', $benchmarkPolicyCheckout);
        $this->assertStringNotContainsString('release:split:validate', $this->extractJob('benchmark-policy'));

        $this->assertMatchesPattern('/persist-credentials:\s*false/', $workspaceQualityCheckout);
        $this->assertDoesNotMatchPattern('/fetch-depth:\s*0/', $workspaceQualityCheckout);
        $this->assertStringNotContainsString('release:split:validate', $this->extractJob('workspace-quality'));
    }

    public function testWorkspaceQualityMatrixUsesLockfileInstallAndApprovedAggregateCommand(): void
    {
        $job = $this->extractJob('workspace-quality');

        $this->assertMatchesPattern('/name:\s*Workspace quality \(PHP \$\{\{ matrix\.php \}\}\)/', $job);
        $this->assertMatchesPattern('/strategy:\s*\R\s{6}fail-fast:\s*false/', $job);
        $this->assertMatchesPattern('/php:\s*\R\s{10}- \'8\.4\'\s*\R\s{10}- \'8\.5\'/m', $job);
        $this->assertStringContainsString('composer validate --strict --check-lock', $job);
        $this->assertStringContainsString('composer install --no-interaction --no-progress --prefer-dist', $job);
        $this->assertStringContainsString('composer quality', $job);
        $this->assertDoesNotMatchPattern('/composer update|style:fix|continue-on-error|--ignore-platform-reqs?/', $job);
        $this->assertDoesNotMatchPattern('/composer (architecture|analyse|style:check|test)(?:\s|$)/', $job);
    }

    public function testWorkflowExcludesReleasePublishingSecretsCachesAndDeployments(): void
    {
        foreach ([
            '/secrets\./',
            '/deploy(?:ment)?/i',
            '/publish/i',
            '/gh\s+release/i',
            '/actions\/create-release/i',
            '/softprops\/action-gh-release/i',
            '/ncipollo\/release-action/i',
            '/git\s+tag\b/i',
            '/git\s+push(?:\s+[^\r\n]*)?\s+--tags\b/i',
            '/git\s+push(?:\s+[^\r\n]*)?\s+tags\//i',
            '/packagist/i',
            '/release_(?:token|key|secret|credential)/i',
            '/release-(?:token|key|secret|credential)/i',
            '/upload-artifact/',
            '/codecov|coveralls/i',
            '/actions\/cache/',
            '/environment:/',
            '/sudo\b/',
            '/--ignore-platform-reqs?/',
            '/config\.platform\.php/',
        ] as $pattern) {
            $this->assertDoesNotMatchPattern($pattern, $this->workflow);
        }
    }

    public function testDocumentationRecordsContinuousIntegrationCompatibilityEvidence(): void
    {
        $workspaceReadme = $this->readProjectFile('DEVELOPMENT.md');
        $changelog = $this->readProjectFile('CHANGELOG.md');

        foreach ([
            '/## Continuous Integration/',
            '/\.github\/workflows\/quality\.yml/',
            '/EvolvePHP 2 Quality/',
            '/pull requests.*2\.x|2\.x.*pull requests/i',
            '/pushes.*2\.x|2\.x.*pushes/i',
            '/manual.*dispatch|workflow_dispatch/i',
            '/contents:\s*read/',
            '/concurrency.*cancel|cancel.*concurrency/i',
            '/Ubuntu 24\.04/',
            '/policy job.*PHP 8\.4|PHP 8\.4.*policy job/i',
            '/Architecture and Documentation.*root PHPUnit 13|root PHPUnit 13.*Architecture and Documentation/i',
            '/EvolvePHP 1 runtime.*not part|not part.*EvolvePHP 1 runtime/i',
            '/ quality matrix.*PHP 8\.4.*PHP 8\.5|PHP 8\.4.*PHP 8\.5.*root quality matrix/i',
            '/Composer validation.*before.*install|validate.*before.*install/i',
            '/lockfile.*composer install|composer install.*lockfile/i',
            '/no `composer update`|not run `composer update`/i',
            '/no platform-requirement bypass|platform-requirement bypass.*not/i',
            '/no initial dependency cache|dependency cache.*not/i',
            '/immutable.*full-SHA|full-SHA.*immutable/i',
            '/release comments.*SHA|SHA.*release comments/i',
            '/CI matrix.*successfully executed|successfully executed.*CI matrix/i',
            '/ quality.*passes.*PHP 8\.4.*PHP 8\.5|PHP 8\.4.*PHP 8\.5.*root quality.*passes/i',
            '/current.*(?:workspace|tooling|package foundation)|(?:workspace|tooling|package foundation).*current/i',
            '/EvolvePHP 1 runtime.*(?:not part|excluded)|(?:not part|excluded).*EvolvePHP 1 runtime/i',
            '/runtime implementation.*(?:incomplete|not complete)|(?:incomplete|not complete).*runtime implementation/i',
        ] as $pattern) {
            $this->assertMatchesPattern($pattern, $workspaceReadme);
        }

        $this->assertMatchesPattern('/Phase 2\.6/i', $changelog);
        $this->assertMatchesPattern('/PHP 8\.4\/8\.5 root quality matrix/i', $changelog);
        $this->assertMatchesPattern('/successful initial CI execution|initial GitHub Actions execution completed successfully|CI execution.*successfully/i', $changelog);
        $this->assertMatchesPattern('/separate PHP 8\.4 root-policy job/i', $changelog);
        $this->assertMatchesPattern('/lockfile-based workspace installation/i', $changelog);
        $this->assertMatchesPattern('/immutable action pinning/i', $changelog);
        $this->assertDoesNotMatchPattern('/branch protection.*active|required checks|deployment|publishing|runtime implementation.*complete|legacy EvolvePHP 1 runtime.*PHP 8\.5/i', $changelog);
    }

    private function extractTopLevelBlock(string $heading): string
    {
        $pattern = '/^' . preg_quote($heading, '/') . ':\s*\R(?P<block>.*?)(?=^[a-zA-Z_-]+:\s*|\z)/ms';

        $this->assertSame(1, preg_match($pattern, $this->workflow, $matches), 'Missing top-level block: ' . $heading);

        return $matches['block'];
    }

    private function extractJob(string $job): string
    {
        $jobs = $this->extractTopLevelBlock('jobs');
        $pattern = '/^\s{2}' . preg_quote($job, '/') . ':\s*\R(?P<job>.*?)(?=^\s{2}[a-zA-Z0-9_-]+:\s*|\z)/ms';

        $this->assertSame(1, preg_match($pattern, $jobs, $matches), 'Missing job: ' . $job);

        return $matches['job'];
    }

    private function extractStep(string $job, string $stepName): string
    {
        $pattern = '/^\s{6}- name:\s*' . preg_quote($stepName, '/') . '\s*\R(?P<step>.*?)(?=^\s{6}- name:\s*|\z)/ms';

        $this->assertSame(1, preg_match($pattern, $job, $matches), 'Missing step: ' . $stepName);

        return $matches['step'];
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

    private function assertMatchesPattern(string $pattern, string $content): void
    {
        $this->assertSame(1, preg_match($pattern, $content), 'Failed asserting that content matches ' . $pattern);
    }

    private function assertDoesNotMatchPattern(string $pattern, string $content): void
    {
        $this->assertSame(0, preg_match($pattern, $content), 'Failed asserting that content does not match ' . $pattern);
    }
}
