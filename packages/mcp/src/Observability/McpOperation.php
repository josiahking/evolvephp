<?php

declare(strict_types=1);

namespace Evolve\Mcp\Observability;

/** Immutable operational metadata. Payloads and raw capability identities never leave this boundary. */
final readonly class McpOperation
{
    private const KINDS = ['register', 'discover', 'invoke', 'connect', 'transport', 'close'];
    private const TRANSPORTS = ['http', 'stdio', 'local'];
    private const OUTCOMES = ['success', 'failure', 'denied'];
    private const FAILURES = ['policy', 'configuration', 'secret', 'security', 'connection', 'discovery', 'operation', 'transport', 'cleanup', 'unusable', 'unknown'];

    /** @var array<string, string|int> */
    private array $fields;

    public function __construct(
        string $kind,
        string $transport,
        string $outcome,
        ?string $capabilityIdentity = null,
        ?string $correlation = null,
        ?int $durationNs = null,
        ?string $failureCategory = null,
    ) {
        if (!in_array($kind, self::KINDS, true) || !in_array($transport, self::TRANSPORTS, true)
            || !in_array($outcome, self::OUTCOMES, true)
            || ($failureCategory !== null && !in_array($failureCategory, self::FAILURES, true))) {
            throw new \InvalidArgumentException('Invalid MCP operational category.');
        }
        $fields = ['kind' => $kind, 'transport' => $transport, 'outcome' => $outcome];
        if ($capabilityIdentity !== null) {
            $fields['capability_hash'] = hash('sha256', $capabilityIdentity);
        }
        if ($correlation !== null && preg_match('/\A[a-f0-9]{1,32}\z/D', $correlation) === 1) {
            $fields['correlation'] = $correlation;
        }
        if ($durationNs !== null) {
            $fields['duration_ns'] = min(60_000_000_000, max(0, $durationNs));
        }
        if ($failureCategory !== null) {
            $fields['failure_category'] = $failureCategory;
        }
        $this->fields = $fields;
    }

    /** @return array<string, string|int> */
    public function fields(): array
    {
        return $this->fields;
    }
}
