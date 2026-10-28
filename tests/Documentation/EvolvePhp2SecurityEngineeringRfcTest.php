<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2SecurityEngineeringRfcTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testAcceptedMetadataAndAllFifteenSections(): void
    {
        $rfc = $this->read('docs/rfcs/0008-security-engineering-and-vulnerability-response.md');

        self::assertStringStartsWith('# RFC 0008: Security Engineering and Vulnerability Response', $rfc);
        foreach ([
            'Status: Accepted (accepted by maintainer on 2026-10-23)',
            'Author: EvolvePHP maintainer',
            'Created: 2026-10-23',
            'Target release: EvolvePHP 2.0 Stable security engineering',
            'Decision type: Security architecture, maintenance, vulnerability response and assurance',
            'Depends on: RFC 0002',
            'RFC 0003',
            'RFC 0005',
            'SECURITY.md',
            'Supersedes: None',
            'Superseded by: None',
            'Implementation owner: Phase 11.9',
            'Independent assurance: Phase 17',
        ] as $required) {
            self::assertStringContainsString($required, $rfc, $required);
        }

        $headings = [
            'Summary',
            'Problem and Baseline',
            'Goals',
            'Non-Goals',
            'Security Boundaries and Responsibilities',
            'Vulnerability Intake and Confidential Triage',
            'Rapid Security Delivery Without a Second Workflow',
            'Continuous Verification',
            'EvolvePHP-Specific Minimum Threat Model',
            'PHP, Composer and Evolve Doctor',
            'Release, Disclosure and Support',
            'Phased Implementation Proposal',
            'Acceptance Criteria',
            'Alternatives Considered and Rejected',
            'Implementation Decisions Remaining Open After Acceptance',
        ];
        self::assertSame(15, preg_match_all('/^## [0-9]+\\. /m', $rfc));
        foreach ($headings as $index => $heading) {
            self::assertStringContainsString('## ' . ($index + 1) . '. ' . $heading, $rfc);
        }
    }

    public function testPrivateIntakeAndRiskClassificationDoNotBecomeServiceGuarantees(): void
    {
        $rfc = $this->read('docs/rfcs/0008-security-engineering-and-vulnerability-response.md');
        $triage = $this->section($rfc, 6);

        self::assertMatchesRegularExpression('/SECURITY\\.md.*private reporting/is', $triage);
        self::assertMatchesRegularExpression('/GitHub private reporting.*conditional/is', $triage);
        self::assertMatchesRegularExpression('/never place undisclosed exploit details in public issues/i', $triage);
        foreach (['CONFIRMED', 'LIKELY / REQUIRES REPRODUCTION', 'CONDITIONAL', 'NOT APPLICABLE', 'UNKNOWN'] as $classification) {
            self::assertStringContainsString($classification, $triage);
        }
        self::assertMatchesRegularExpression('/proposed internal targets.*not accepted SLAs/is', $triage);
        self::assertStringContainsString('For Critical or actively exploited cases, apply available operational mitigation immediately', $triage);
        self::assertMatchesRegularExpression('/Critical.*4 h.*24.?48 h/is', $triage);
        self::assertMatchesRegularExpression('/High.*one business day.*seven.day/is', $triage);
        self::assertMatchesRegularExpression('/Medium.*three business days.*next maintenance release/is', $triage);
        self::assertMatchesRegularExpression('/Low.*ordinary backlog/is', $triage);
        self::assertMatchesRegularExpression('/off.hours cover.*await.*acceptance/is', $triage);
        self::assertDoesNotMatchRegularExpression('/guarantee(?:d|s)? (?:24\\/7|response|remediation)/i', $rfc);
    }

    public function testFrameworkRuntimeAndOperatorBoundariesRemainDistinct(): void
    {
        $rfc = $this->read('docs/rfcs/0008-security-engineering-and-vulnerability-response.md');
        $boundary = $this->section($rfc, 5);
        $runtime = $this->section($rfc, 10);

        self::assertMatchesRegularExpression('/EvolvePHP owns.*framework.*contracts.*defaults.*optional packages/is', $boundary);
        self::assertMatchesRegularExpression('/PHP interpreter.*extensions.*SAPI.*upstreams and operators/is', $boundary);
        self::assertMatchesRegularExpression('/Operators own.*application authorization.*keys.*host.*network/is', $boundary);
        self::assertMatchesRegularExpression('/Composer constraints.*not security patch attestations/is', $runtime);
        self::assertMatchesRegularExpression('/vendor.backport.*provenance/is', $runtime);
        self::assertMatchesRegularExpression('/Evolve Doctor.*read.only.*UNKNOWN/is', $runtime);
        self::assertMatchesRegularExpression('/PHP 7\\.4.*legacy remote.client tests.*do not establish.*support/is', $runtime);
        self::assertMatchesRegularExpression('/restart.*rebuild.*unpatched interpreter.*does not fix/is', $runtime);
    }

    public function testDeliveryAndImplementationDoNotCreateABypassOrImplementedCapability(): void
    {
        $rfc = $this->read('docs/rfcs/0008-security-engineering-and-vulnerability-response.md');
        $delivery = $this->section($rfc, 7);
        $implementation = $this->section($rfc, 12);
        $release = $this->section($rfc, 11);

        self::assertMatchesRegularExpression('/owner.approved.*expiring emergency exception.*separately granted/is', $delivery);
        self::assertMatchesRegularExpression('/this RFC grants none/i', $delivery);
        self::assertMatchesRegularExpression('/audit.*branch.*patch.*test.*stage.*commit.*committed.ref.*push.*PR.*merge.*post.merge assurance/is', $delivery);
        self::assertMatchesRegularExpression('/separate maintainer authorizations.*stage.*commit.*push.*PR.*merge.*tag.*release.*publication/is', $delivery);
        self::assertMatchesRegularExpression('/Phase 11\\.9.*implementation/is', $implementation);
        self::assertMatchesRegularExpression('/Phase 17.*independent/is', $implementation);
        self::assertMatchesRegularExpression('/SAST.*secrets.*dependency.*periodic.scan.*separately selected/is', $implementation);
        self::assertMatchesRegularExpression('/budget.*hosting.*privacy.*retention.*CI.blocking.*open/is', $implementation);
        self::assertMatchesRegularExpression('/green quality CI.*clean Composer audit.*cannot imply a secure release/is', $release);
        self::assertMatchesRegularExpression('/unresolved confirmed.*Critical.*High.*fail closed/is', $release);
        self::assertDoesNotMatchRegularExpression('/(?:automatic|blanket) (?:emergency )?(?:gate )?bypass (?:is|has been) (?:granted|allowed)/i', $rfc);
        self::assertDoesNotMatchRegularExpression('/(?:SAST|Evolve Doctor|GitHub private reporting) (?:is|has been) (?:implemented|enabled)/i', $rfc);
    }

    public function testThreatModelAndAssuranceRequireNegativePathEvidence(): void
    {
        $rfc = $this->read('docs/rfcs/0008-security-engineering-and-vulnerability-response.md');
        $threats = $this->section($rfc, 9);
        $verification = $this->section($rfc, 8);
        $acceptance = $this->section($rfc, 13);

        foreach (['HTTP', 'CSRF', 'CORS', 'SQL injection', 'escaping', 'module', 'plugin', 'Bridge', 'MCP', 'prompt injection', 'persistent worker', 'Observe', 'Insight', 'CI', 'provenance'] as $term) {
            self::assertStringContainsString(strtolower($term), strtolower($threats), $term);
        }
        self::assertStringContainsString('object-storage traversal', strtolower($threats));
        self::assertStringContainsString('tenant/identity leakage', strtolower($threats));
        self::assertMatchesRegularExpression('/failing.before.*passing.after.*denial cases/is', $threats);
        self::assertMatchesRegularExpression('/Semgrep.*Gitleaks.*Trivy.*possible tools.*not accepted mandates/is', $verification);
        self::assertMatchesRegularExpression('/DAST.*isolated disposable.*authorization.*fake data.*restricted egress/is', $verification);
        self::assertMatchesRegularExpression('/false.positive adjudication.*negative.path.*exact package.*secret.safe.*runtime.backport evidence/is', $acceptance);
        self::assertMatchesRegularExpression('/SECURITY\\.md.*separately authorized future changes/is', $acceptance);
        self::assertMatchesRegularExpression('/updated SECURITY\\.md and development\/security guidance.*separately authorized future changes/is', $acceptance);
    }

    public function testSevenOpenQuestionsAndBothIndexes(): void
    {
        $rfc = $this->read('docs/rfcs/0008-security-engineering-and-vulnerability-response.md');
        $open = $this->section($rfc, 15);

        self::assertSame(7, preg_match_all('/^[1-7]\\. /m', $open));
        foreach (['off-hours cover', 'private vulnerability reporting', 'block a release', '34 planned packages', 'scanner versions', 'retention/access', 'expiry-bounded exception'] as $term) {
            self::assertStringContainsString($term, $open, $term);
        }
        self::assertMatchesRegularExpression('/ACCEPTED as architecture on 2026-10-23.*supersedes neither RFC 0003 nor RFC 0005.*no gate exception.*no support SLA.*no security engineering implementation.*no publishing decision/is', $open);

        $repositoryIndex = $this->read('docs/rfcs/README.md');
        self::assertSame(1, substr_count($repositoryIndex, '[RFC 0008: Security Engineering and Vulnerability Response](0008-security-engineering-and-vulnerability-response.md) - Accepted'));
        self::assertMatchesRegularExpression('/\\[RFC 0007:.*\\) - Accepted\\R- \\[RFC 0008:/', $repositoryIndex);

        $siteIndex = $this->read('website/docs/next/project/rfcs.md');
        $link = 'https://github.com/josiahking/evolvephp/blob/2.x/docs/rfcs/0008-security-engineering-and-vulnerability-response.md';
        self::assertSame(1, substr_count($siteIndex, $link));
        self::assertMatchesRegularExpression('/\\| \\[0007:.*\\R\\| \\[0008: Security engineering and vulnerability response\\]/', $siteIndex);
    }

    private function read(string $path): string
    {
        $fullPath = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        self::assertFileExists($fullPath, $path);
        $content = file_get_contents($fullPath);
        self::assertIsString($content, $path);
        return $content;
    }

    private function section(string $rfc, int $number): string
    {
        $matched = preg_match('/^## ' . $number . '\\. [^\\r\\n]+\\R(.*?)(?=^## [0-9]+\\. |\\z)/ms', $rfc, $matches);
        self::assertSame(1, $matched, 'Missing section ' . $number);
        return $matches[1];
    }
}
