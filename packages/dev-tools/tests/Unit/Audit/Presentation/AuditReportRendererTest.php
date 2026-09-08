<?php

declare(strict_types=1);

namespace Evolve\DevTools\Tests\Unit\Audit\Presentation;

use Evolve\DevTools\Audit\AuditFinding;
use Evolve\DevTools\Audit\AuditReport;
use Evolve\DevTools\Audit\AuditSeverity;
use Evolve\DevTools\Audit\Presentation\AuditReportRenderer;
use PHPUnit\Framework\TestCase;

final class AuditReportRendererTest extends TestCase
{
    public function testEmptyReportRendersDeterministicText(): void
    {
        $renderer = new AuditReportRenderer();

        self::assertSame("Audit report\nFindings: 0", $renderer->renderText(new AuditReport([])));
        self::assertStringEndsNotWith("\n", $renderer->renderText(new AuditReport([])));
        self::assertSame(
            $renderer->renderText(new AuditReport([])),
            $renderer->renderText(new AuditReport([])),
        );
    }

    public function testEmptyReportJsonContainsSchemaVersionAndEmptyFindings(): void
    {
        $json = (new AuditReportRenderer())->renderJson(new AuditReport([]));

        self::assertSame("{\n    \"schema_version\": 1,\n    \"findings\": []\n}", $json);
        self::assertStringEndsNotWith("\n", $json);
    }

    public function testJsonUsesAcceptedSeverityValuesAndStableFindingFieldOrder(): void
    {
        $json = (new AuditReportRenderer())->renderJson(new AuditReport([
            new AuditFinding('audit.info', AuditSeverity::Info, 'Info evidence.', []),
            new AuditFinding('audit.warning', AuditSeverity::Warning, 'Warning evidence.', []),
            new AuditFinding('audit.risk', AuditSeverity::Risk, 'Risk evidence.', []),
        ]));

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['identifier', 'severity', 'message', 'evidence'], array_keys($decoded['findings'][0]));
        self::assertSame(['info', 'warning', 'risk'], array_column($decoded['findings'], 'severity'));
    }

    public function testFindingOrderIsPreserved(): void
    {
        $json = (new AuditReportRenderer())->renderJson(new AuditReport([
            new AuditFinding('zeta.finding', AuditSeverity::Risk, 'Zeta.', []),
            new AuditFinding('alpha.finding', AuditSeverity::Info, 'Alpha.', []),
        ]));

        self::assertSame(['zeta.finding', 'alpha.finding'], array_column(json_decode($json, true, flags: JSON_THROW_ON_ERROR)['findings'], 'identifier'));
    }

    public function testEvidenceSerializesAsPlainDataWithCanonicalAssociativeKeysAndPreservedLists(): void
    {
        $report = new AuditReport([
            new AuditFinding('audit.evidence', AuditSeverity::Info, 'Evidence.', [
                'zeta' => 'last',
                'alpha' => [
                    'zeta' => true,
                    'alpha' => null,
                    'list' => [
                        ['b' => 2, 'a' => 1],
                        ['d' => 4, 'c' => 3],
                    ],
                ],
            ]),
        ]);

        $json = (new AuditReportRenderer())->renderJson($report);

        self::assertStringContainsString("\"alpha\": {\n                    \"alpha\": null,\n                    \"list\": [", $json);
        self::assertStringContainsString("\"a\": 1,\n                            \"b\": 2", $json);
        self::assertSame([['a' => 1, 'b' => 2], ['c' => 3, 'd' => 4]], json_decode($json, true, flags: JSON_THROW_ON_ERROR)['findings'][0]['evidence']['alpha']['list']);
    }

    public function testEquivalentReportsRenderByteForByteEquivalently(): void
    {
        $renderer = new AuditReportRenderer();

        $first = new AuditReport([
            new AuditFinding('audit.equivalent', AuditSeverity::Warning, 'Equivalent.', [
                'b' => ['z' => 2, 'a' => 1],
                'a' => 'first',
            ]),
        ]);
        $second = new AuditReport([
            new AuditFinding('audit.equivalent', AuditSeverity::Warning, 'Equivalent.', [
                'a' => 'first',
                'b' => ['a' => 1, 'z' => 2],
            ]),
        ]);

        self::assertSame($renderer->renderJson($first), $renderer->renderJson($second));
        self::assertSame($renderer->renderText($first), $renderer->renderText($second));
    }

    public function testOutputDoesNotAddTimestampsRuntimeMetadataOrAbsoluteTargetPaths(): void
    {
        $renderer = new AuditReportRenderer();
        $report = new AuditReport([
            new AuditFinding('composer_json.present', AuditSeverity::Info, 'Root composer.json was found.', ['path' => 'composer.json']),
        ]);

        $combined = $renderer->renderText($report) . $renderer->renderJson($report);

        self::assertStringNotContainsString((string) getcwd(), $combined);
        self::assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $combined);
        self::assertStringNotContainsString('hostname', strtolower($combined));
    }

    public function testTextContainsCountAndEveryFindingWithEvidence(): void
    {
        $text = (new AuditReportRenderer())->renderText(new AuditReport([
            new AuditFinding('audit.warning', AuditSeverity::Warning, 'Warning evidence.', ['path' => 'composer.json']),
            new AuditFinding('audit.risk', AuditSeverity::Risk, 'Risk evidence.', ['occurrences' => [['path' => 'src/App.php', 'line' => 3]]]),
        ]));

        self::assertStringContainsString('Findings: 2', $text);
        self::assertStringContainsString('[warning] audit.warning: Warning evidence.', $text);
        self::assertStringContainsString('Evidence: {"path":"composer.json"}', $text);
        self::assertStringContainsString('[risk] audit.risk: Risk evidence.', $text);
        self::assertStringContainsString('Evidence: {"occurrences":[{"line":3,"path":"src/App.php"}]}', $text);
    }
}
