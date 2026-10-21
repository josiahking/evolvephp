<?php

declare(strict_types=1);

namespace Evolve\Insight\Mcp;

use Evolve\Insight\Capture\DiagnosticAttribute;
use Evolve\Insight\Capture\DiagnosticDataClassification;
use Evolve\Insight\Capture\DiagnosticEntry;
use Evolve\Insight\Capture\DiagnosticEntrySink;
use Throwable;

/** Local operational summaries; capability hashes and correlation tokens are omitted. */
final readonly class McpOperationDiagnostics
{
    public function __construct(private DiagnosticEntrySink $sink, private string $executionIdentifier) {}

    /** @param array<string, mixed> $fields */
    public function record(array $fields): void
    {
        foreach (['kind' => ['register', 'discover', 'invoke', 'connect', 'transport', 'close'], 'transport' => ['http', 'stdio', 'local'], 'outcome' => ['success', 'failure', 'denied']] as $key => $allowed) {
            if (!isset($fields[$key]) || !is_string($fields[$key]) || !in_array($fields[$key], $allowed, true)) {
                throw new \InvalidArgumentException('Invalid MCP operational category.');
            }
        }
        if (array_diff(array_keys($fields), ['kind', 'transport', 'outcome', 'capability_hash', 'correlation', 'duration_ns', 'failure_category']) !== []) {
            throw new \InvalidArgumentException('Unexpected MCP operational field.');
        }
        if (isset($fields['capability_hash']) && (!is_string($fields['capability_hash']) || preg_match('/\A[a-f0-9]{64}\z/D', $fields['capability_hash']) !== 1)) {
            throw new \InvalidArgumentException('Invalid capability hash.');
        }
        if (isset($fields['correlation']) && (!is_string($fields['correlation']) || preg_match('/\A[a-f0-9]{1,32}\z/D', $fields['correlation']) !== 1)) {
            throw new \InvalidArgumentException('Invalid MCP correlation.');
        }
        if (isset($fields['duration_ns']) && (!is_int($fields['duration_ns']) || $fields['duration_ns'] < 0 || $fields['duration_ns'] > 60_000_000_000)) {
            throw new \InvalidArgumentException('Invalid MCP duration.');
        }
        if (isset($fields['failure_category']) && (!is_string($fields['failure_category']) || !in_array($fields['failure_category'], ['policy', 'configuration', 'secret', 'security', 'connection', 'discovery', 'operation', 'transport', 'cleanup', 'unusable', 'unknown'], true))) {
            throw new \InvalidArgumentException('Invalid MCP failure category.');
        }
        $classification = DiagnosticDataClassification::PublicOperationalMetadata;
        $attributes = [
            new DiagnosticAttribute('kind', $classification, $fields['kind']),
            new DiagnosticAttribute('transport', $classification, $fields['transport']),
            new DiagnosticAttribute('outcome', $classification, $fields['outcome']),
        ];
        if (isset($fields['duration_ns'])) {
            $attributes[] = new DiagnosticAttribute('duration_ns', $classification, $fields['duration_ns']);
        }
        if (isset($fields['failure_category'])) {
            $attributes[] = new DiagnosticAttribute('failure_category', $classification, $fields['failure_category']);
        }
        try {
            $this->sink->capture(new DiagnosticEntry($this->executionIdentifier, 'evolve.mcp', $fields['kind'], $attributes));
        } catch (Throwable) {
            // Local diagnostics cannot affect MCP behavior.
        }
    }
}
