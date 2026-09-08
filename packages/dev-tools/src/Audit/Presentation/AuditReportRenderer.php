<?php

declare(strict_types=1);

namespace Evolve\DevTools\Audit\Presentation;

use Evolve\DevTools\Audit\AuditFinding;
use Evolve\DevTools\Audit\AuditReport;
use JsonException;

/**
 * @experimental
 */
final readonly class AuditReportRenderer
{
    public function renderText(AuditReport $report): string
    {
        $lines = [
            'Audit report',
            'Findings: ' . count($report),
        ];

        foreach ($report->findings() as $finding) {
            $lines[] = sprintf(
                '[%s] %s: %s',
                $finding->severity()->value,
                $finding->identifier(),
                $finding->message(),
            );
            $lines[] = 'Evidence: ' . $this->encodeCompact($this->canonicalizeEvidence($finding->evidence()));
        }

        return implode("\n", $lines);
    }

    public function renderJson(AuditReport $report): string
    {
        $findings = array_map(
            fn(AuditFinding $finding): array => [
                'identifier' => $finding->identifier(),
                'severity' => $finding->severity()->value,
                'message' => $finding->message(),
                'evidence' => $this->canonicalizeEvidence($finding->evidence()),
            ],
            $report->findings(),
        );

        return $this->encodePretty([
            'schema_version' => 1,
            'findings' => $findings,
        ]);
    }

    /**
     * @param array<string|int, mixed> $evidence
     * @return array<string|int, mixed>|\stdClass
     */
    private function canonicalizeEvidence(array $evidence): array|\stdClass
    {
        if ($evidence === []) {
            return new \stdClass();
        }

        return $this->canonicalizeArray($evidence);
    }

    /**
     * @param array<string|int, mixed> $value
     * @return array<string|int, mixed>
     */
    private function canonicalizeArray(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn(mixed $item): mixed => is_array($item) ? $this->canonicalizeArray($item) : $item,
                $value,
            );
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalizeArray($item);
            }
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    private function encodeCompact(mixed $value): string
    {
        return $this->encode($value, JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param mixed $value
     */
    private function encodePretty(mixed $value): string
    {
        return $this->encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param mixed $value
     */
    private function encode(mixed $value, int $flags): string
    {
        try {
            return json_encode($value, $flags | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to render audit report JSON.', previous: $exception);
        }
    }
}
