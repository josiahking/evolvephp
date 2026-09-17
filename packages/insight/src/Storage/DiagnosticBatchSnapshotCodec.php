<?php

declare(strict_types=1);

namespace Evolve\Insight\Storage;

final class DiagnosticBatchSnapshotCodec
{
    private const int VERSION = 2;

    private const array VERSION_ONE_PAYLOAD_KEYS = array(
        'version',
        'execution_identifier',
        'execution_kind',
        'observations',
        'dropped_observation_count',
    );

    private const array VERSION_TWO_PAYLOAD_KEYS = array(
        'version',
        'execution_identifier',
        'execution_kind',
        'observations',
        'dropped_observation_count',
        'diagnostic_entries',
        'dropped_diagnostic_entry_count',
    );

    private const array OBSERVATION_KEYS = array(
        'type',
        'outcome',
        'error_type',
        'reuse_decision',
    );

    private const array DIAGNOSTIC_ENTRY_KEYS = array(
        'category',
        'name',
        'attributes',
    );

    private const array DIAGNOSTIC_ATTRIBUTE_KEYS = array(
        'name',
        'value',
    );

    private const array EXECUTION_KINDS = array(
        'http-request',
        'queue-message',
        'scheduled-job',
        'cli-command',
        'worker-task',
    );

    private const array OBSERVATION_TYPES = array(
        'execution-started',
        'handler-completed',
        'scope-close-started',
        'scope-close-completed',
        'quarantine-required',
        'execution-completed',
    );

    private const array OBSERVATION_OUTCOMES = array(
        'succeeded',
        'failed',
    );

    private const array REUSE_DECISIONS = array(
        'reusable',
        'quarantine-required',
    );

    public function encode(DiagnosticBatchSnapshot $snapshot): string
    {
        $this->requireAllowedValue($snapshot->executionKind(), self::EXECUTION_KINDS, 'Execution kind');

        return json_encode(
            array(
                'version' => self::VERSION,
                'execution_identifier' => $snapshot->executionIdentifier(),
                'execution_kind' => $snapshot->executionKind(),
                'observations' => array_map(
                    fn (DiagnosticObservationSnapshot $observation): array => $this->encodeObservation($observation),
                    $snapshot->observations(),
                ),
                'dropped_observation_count' => $snapshot->droppedObservationCount(),
                'diagnostic_entries' => array_map(
                    fn (DiagnosticEntrySnapshot $entry): array => $this->encodeEntry($entry),
                    $snapshot->diagnosticEntries(),
                ),
                'dropped_diagnostic_entry_count' => $snapshot->droppedDiagnosticEntryCount(),
            ),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * @return array{type: string, outcome: ?string, error_type: ?string, reuse_decision: ?string}
     */
    private function encodeObservation(DiagnosticObservationSnapshot $observation): array
    {
        $this->requireAllowedValue($observation->type(), self::OBSERVATION_TYPES, 'Observation type');

        $outcome = $observation->outcome();
        if ($outcome !== null) {
            $this->requireAllowedValue($outcome, self::OBSERVATION_OUTCOMES, 'Observation outcome');
        }

        $reuseDecision = $observation->reuseDecision();
        if ($reuseDecision !== null) {
            $this->requireAllowedValue($reuseDecision, self::REUSE_DECISIONS, 'Process reuse decision');
        }

        return array(
            'type' => $observation->type(),
            'outcome' => $outcome,
            'error_type' => $observation->errorType(),
            'reuse_decision' => $reuseDecision,
        );
    }

    /**
     * @return array{category: string, name: string, attributes: list<array{name: string, value: string|int|float|bool|null}>}
     */
    private function encodeEntry(DiagnosticEntrySnapshot $entry): array
    {
        return array(
            'category' => $entry->category(),
            'name' => $entry->name(),
            'attributes' => array_map(
                fn (DiagnosticEntryAttributeSnapshot $attribute): array => $this->encodeAttribute($attribute),
                $entry->attributes(),
            ),
        );
    }

    /**
     * @return array{name: string, value: string|int|float|bool|null}
     */
    private function encodeAttribute(DiagnosticEntryAttributeSnapshot $attribute): array
    {
        return array(
            'name' => $attribute->name(),
            'value' => $attribute->value(),
        );
    }

    public function decode(string $payload): DiagnosticBatchSnapshot
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Diagnostic batch snapshot payload must be valid JSON.', 0, $exception);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \InvalidArgumentException('Diagnostic batch snapshot payload must be a JSON object.');
        }

        if (!array_key_exists('version', $decoded)) {
            throw new \InvalidArgumentException('Diagnostic batch snapshot payload has an invalid schema.');
        }

        if ($decoded['version'] === 1) {
            return $this->decodeVersionOne($decoded);
        }

        if ($decoded['version'] === self::VERSION) {
            return $this->decodeVersionTwo($decoded);
        }

        throw new \InvalidArgumentException('Diagnostic batch snapshot payload version is not supported.');
    }

    /**
     * @param array<mixed> $decoded
     */
    private function decodeVersionOne(array $decoded): DiagnosticBatchSnapshot
    {
        $this->requireKeys($decoded, self::VERSION_ONE_PAYLOAD_KEYS, 'Diagnostic batch snapshot payload');

        return $this->decodeSnapshot(
            $decoded,
            array(),
            0,
        );
    }

    /**
     * @param array<mixed> $decoded
     */
    private function decodeVersionTwo(array $decoded): DiagnosticBatchSnapshot
    {
        $this->requireKeys($decoded, self::VERSION_TWO_PAYLOAD_KEYS, 'Diagnostic batch snapshot payload');

        if (!is_array($decoded['diagnostic_entries']) || !array_is_list($decoded['diagnostic_entries'])) {
            throw new \InvalidArgumentException('Diagnostic batch snapshot diagnostic entries must be a list.');
        }

        $droppedDiagnosticEntryCount = $decoded['dropped_diagnostic_entry_count'];
        if (!is_int($droppedDiagnosticEntryCount)) {
            throw new \InvalidArgumentException('Dropped diagnostic entry count must be an integer.');
        }

        return $this->decodeSnapshot(
            $decoded,
            array_map(
                fn (mixed $entry): DiagnosticEntrySnapshot => $this->decodeEntry($entry),
                $decoded['diagnostic_entries'],
            ),
            $droppedDiagnosticEntryCount,
        );
    }

    /**
     * @param array<mixed> $decoded
     * @param list<DiagnosticEntrySnapshot> $diagnosticEntries
     */
    private function decodeSnapshot(array $decoded, array $diagnosticEntries, int $droppedDiagnosticEntryCount): DiagnosticBatchSnapshot
    {
        if (!is_int($decoded['version'])) {
            throw new \InvalidArgumentException('Diagnostic batch snapshot payload version is not supported.');
        }

        $identifier = $this->requireString($decoded['execution_identifier'], 'Execution identifier');
        $kind = $this->requireString($decoded['execution_kind'], 'Execution kind');
        $this->requireAllowedValue($kind, self::EXECUTION_KINDS, 'Execution kind');

        if (!is_array($decoded['observations']) || !array_is_list($decoded['observations'])) {
            throw new \InvalidArgumentException('Diagnostic batch snapshot observations must be a list.');
        }

        $droppedObservationCount = $decoded['dropped_observation_count'];
        if (!is_int($droppedObservationCount)) {
            throw new \InvalidArgumentException('Dropped observation count must be an integer.');
        }

        return new DiagnosticBatchSnapshot(
            $identifier,
            $kind,
            array_map(
                fn (mixed $observation): DiagnosticObservationSnapshot => $this->decodeObservation($observation),
                $decoded['observations'],
            ),
            $droppedObservationCount,
            $diagnosticEntries,
            $droppedDiagnosticEntryCount,
        );
    }

    private function decodeObservation(mixed $observation): DiagnosticObservationSnapshot
    {
        if (!is_array($observation) || array_is_list($observation)) {
            throw new \InvalidArgumentException('Diagnostic observation snapshot payload must be a JSON object.');
        }

        $this->requireKeys($observation, self::OBSERVATION_KEYS, 'Diagnostic observation snapshot payload');

        $type = $this->requireString($observation['type'], 'Observation type');
        $this->requireAllowedValue($type, self::OBSERVATION_TYPES, 'Observation type');

        $outcome = $this->requireNullableString($observation['outcome'], 'Observation outcome');
        if ($outcome !== null) {
            $this->requireAllowedValue($outcome, self::OBSERVATION_OUTCOMES, 'Observation outcome');
        }

        $reuseDecision = $this->requireNullableString($observation['reuse_decision'], 'Process reuse decision');
        if ($reuseDecision !== null) {
            $this->requireAllowedValue($reuseDecision, self::REUSE_DECISIONS, 'Process reuse decision');
        }

        return new DiagnosticObservationSnapshot(
            $type,
            $outcome,
            $this->requireNullableString($observation['error_type'], 'Observation error type'),
            $reuseDecision,
        );
    }

    private function decodeEntry(mixed $entry): DiagnosticEntrySnapshot
    {
        if (!is_array($entry) || array_is_list($entry)) {
            throw new \InvalidArgumentException('Diagnostic entry snapshot payload must be a JSON object.');
        }

        $this->requireKeys($entry, self::DIAGNOSTIC_ENTRY_KEYS, 'Diagnostic entry snapshot payload');

        if (!is_array($entry['attributes']) || !array_is_list($entry['attributes'])) {
            throw new \InvalidArgumentException('Diagnostic entry snapshot attributes must be a list.');
        }

        return new DiagnosticEntrySnapshot(
            $this->requireString($entry['category'], 'Diagnostic entry category'),
            $this->requireString($entry['name'], 'Diagnostic entry name'),
            array_map(
                fn (mixed $attribute): DiagnosticEntryAttributeSnapshot => $this->decodeAttribute($attribute),
                $entry['attributes'],
            ),
        );
    }

    private function decodeAttribute(mixed $attribute): DiagnosticEntryAttributeSnapshot
    {
        if (!is_array($attribute) || array_is_list($attribute)) {
            throw new \InvalidArgumentException('Diagnostic entry attribute snapshot payload must be a JSON object.');
        }

        $this->requireKeys($attribute, self::DIAGNOSTIC_ATTRIBUTE_KEYS, 'Diagnostic entry attribute snapshot payload');

        $value = $attribute['value'];
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value) && $value !== null) {
            throw new \InvalidArgumentException('Diagnostic entry attribute value must be primitive.');
        }

        return new DiagnosticEntryAttributeSnapshot(
            $this->requireString($attribute['name'], 'Diagnostic entry attribute name'),
            $value,
        );
    }

    /**
     * @param array<mixed> $payload
     * @param list<string> $expectedKeys
     */
    private function requireKeys(array $payload, array $expectedKeys, string $context): void
    {
        $actualKeys = array_keys($payload);
        sort($actualKeys);

        $sortedExpectedKeys = $expectedKeys;
        sort($sortedExpectedKeys);

        if ($actualKeys !== $sortedExpectedKeys) {
            throw new \InvalidArgumentException($context . ' has an invalid schema.');
        }
    }

    private function requireString(mixed $value, string $field): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException($field . ' must be a string.');
        }

        return $value;
    }

    private function requireNullableString(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->requireString($value, $field);
    }

    /**
     * @param list<string> $allowedValues
     */
    private function requireAllowedValue(string $value, array $allowedValues, string $field): void
    {
        if (!in_array($value, $allowedValues, true)) {
            throw new \InvalidArgumentException($field . ' is not supported by this diagnostic snapshot format.');
        }
    }
}
