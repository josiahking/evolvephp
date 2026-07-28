<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2VisionRfcTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testRfc0001ExistsWithAcceptedMetadata(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0001-evolvephp-2-vision-and-scope.md');

        $this->assertMatchesPattern('/#\s*RFC\s+0001:\s*EvolvePHP 2 Vision, Scope and Non-Goals/i', $content);
        $this->assertMatchesPattern('/Status:\s*Accepted/i', $content);
        $this->assertMatchesPattern('/Target release:\s*EvolvePHP 2\.0/i', $content);
        $this->assertMatchesPattern('/Decision type:\s*Product and architecture direction/i', $content);
    }

    public function testSummaryAndVisionPositioningAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0001-evolvephp-2-vision-and-scope.md');

        $this->assertMatchesPattern('/cloud-ready/i', $content);
        $this->assertMatchesPattern('/plugin-first/i', $content);
        $this->assertMatchesPattern('/modular SaaS/i', $content);
        $this->assertMatchesPattern('/separate redesign/i', $content);
        $this->assertMatchesPattern('/not an in-place refactor/i', $content);
        $this->assertMatchesPattern('/modular monolith first/i', $content);
        $this->assertMatchesPattern('/An EvolvePHP module can run embedded in an application, isolated in a worker, or exposed as a remote service without changing its domain logic/i', $content);
    }

    public function testPhpPolicyAndInteroperabilityAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0001-evolvephp-2-vision-and-scope.md');

        $this->assertMatchesPattern('/Minimum PHP:\s*8\.4/i', $content);
        $this->assertMatchesPattern('/Initially tested:\s*PHP 8\.4 and PHP 8\.5/i', $content);
        $this->assertMatchesPattern('/PHP 7.*not supported.*Evolve Core|Evolve Core.*not support.*PHP 7/is', $content);
        $this->assertMatchesPattern('/Laravel/i', $content);
        $this->assertMatchesPattern('/Symfony/i', $content);
        $this->assertMatchesPattern('/Same-process embedded mode/i', $content);
        $this->assertMatchesPattern('/Sidecar or separately deployed mode/i', $content);
        $this->assertMatchesPattern('/Headless remote-module mode/i', $content);
        $this->assertMatchesPattern('/Evolve Bridge.*2\.0 Beta|2\.0 Beta.*Evolve Bridge/is', $content);
    }

    public function testProductVocabularyAndReleaseScopesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0001-evolvephp-2-vision-and-scope.md');

        $this->assertMatchesPattern('/Evolve Insight/i', $content);
        $this->assertMatchesPattern('/Evolve Observe/i', $content);
        $this->assertMatchesPattern('/Insight.*not.*production telemetry backend|production telemetry backend.*not.*Insight/is', $content);
        $this->assertMatchesPattern('/Observe.*not.*Datadog|Observe.*not.*New Relic|Observe.*not.*Grafana/is', $content);
        $this->assertMatchesPattern('/2\.0 Alpha/i', $content);
        $this->assertMatchesPattern('/2\.0 Beta/i', $content);
        $this->assertMatchesPattern('/2\.0 Stable/i', $content);
        $this->assertMatchesPattern('/2\.1 candidates/i', $content);
        $this->assertMatchesPattern('/2\.2 candidates/i', $content);
        $this->assertMatchesPattern('/Kubernetes.*not required.*2\.0|not required.*2\.0.*Kubernetes/is', $content);
        $this->assertMatchesPattern('/multi-tenancy.*not required.*2\.0|not required.*2\.0.*multi-tenancy/is', $content);
        $this->assertMatchesPattern('/FrankenPHP.*not required.*2\.0|not required.*2\.0.*FrankenPHP/is', $content);
        $this->assertMatchesPattern('/advanced service extraction.*not required.*2\.0|not required.*2\.0.*advanced service extraction/is', $content);
    }

    public function testGovernanceNonGoalsAndAcceptanceGatesAreDocumented(): void
    {
        $content = $this->readProjectFile('docs/rfcs/0001-evolvephp-2-vision-and-scope.md');

        $this->assertMatchesPattern('/Explicit non-goals/i', $content);
        $this->assertMatchesPattern('/will not be a Laravel clone/i', $content);
        $this->assertMatchesPattern('/will not be a Symfony clone/i', $content);
        $this->assertMatchesPattern('/will not support PHP 7 inside Evolve Core/i', $content);
        $this->assertMatchesPattern('/Open-source and commercial boundary/i', $content);
        $this->assertMatchesPattern('/Free framework, paid operational convenience, enterprise confidence and hosted services/i', $content);
        $this->assertMatchesPattern('/Alpha acceptance criteria/i', $content);
        $this->assertMatchesPattern('/Decision governance/i', $content);
        $this->assertMatchesPattern('/Alternatives considered/i', $content);
        $this->assertMatchesPattern('/One tested HTTP request must pass through the EvolvePHP kernel/i', $content);
    }

    public function testRfcIndexAndChangelogReferenceRfc0001(): void
    {
        $index = $this->readProjectFile('docs/rfcs/README.md');
        $changelog = $this->readProjectFile('CHANGELOG.md');

        $this->assertMatchesPattern('/Purpose/i', $index);
        $this->assertMatchesPattern('/Draft/i', $index);
        $this->assertMatchesPattern('/Proposed/i', $index);
        $this->assertMatchesPattern('/Accepted/i', $index);
        $this->assertMatchesPattern('/Rejected/i', $index);
        $this->assertMatchesPattern('/Superseded/i', $index);
        $this->assertMatchesPattern('/0001-evolvephp-2-vision-and-scope\.md/i', $index);
        $this->assertMatchesPattern('/RFC 0001/i', $changelog);
        $this->assertMatchesPattern('/EvolvePHP 2 Vision, Scope and Non-Goals/i', $changelog);
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
