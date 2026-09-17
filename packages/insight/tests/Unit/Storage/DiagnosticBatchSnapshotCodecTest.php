<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit\Storage;

use Evolve\Insight\Capture\DiagnosticAttribute;
use Evolve\Insight\Capture\DiagnosticEntry;
use Evolve\Insight\Storage\DiagnosticBatchSnapshot;
use Evolve\Insight\Storage\DiagnosticBatchSnapshotCodec;
use Evolve\Insight\Storage\DiagnosticEntryAttributeSnapshot;
use Evolve\Insight\Storage\DiagnosticEntrySnapshot;
use Evolve\Insight\Storage\DiagnosticObservationSnapshot;
use PHPUnit\Framework\TestCase;

final class DiagnosticBatchSnapshotCodecTest extends TestCase
{
    public function testEncodingIsDeterministic(): void
    {
        $codec = new DiagnosticBatchSnapshotCodec();
        $snapshot = $this->snapshot();

        self::assertSame($codec->encode($snapshot), $codec->encode($snapshot));
        self::assertSame(
            '{"version":2,"execution_identifier":"execution-1","execution_kind":"http-request","observations":[{"type":"execution-started","outcome":null,"error_type":null,"reuse_decision":null}],"dropped_observation_count":0,"diagnostic_entries":[],"dropped_diagnostic_entry_count":0}',
            $codec->encode($snapshot),
        );
    }

    public function testFullValidSnapshotRoundTrips(): void
    {
        $codec = new DiagnosticBatchSnapshotCodec();
        $snapshot = new DiagnosticBatchSnapshot(
            'execution-1',
            'queue-message',
            array(
                new DiagnosticObservationSnapshot('handler-completed', 'failed', 'RuntimeException', 'quarantine-required'),
            ),
            4,
        );

        self::assertEquals($snapshot, $codec->decode($codec->encode($snapshot)));
    }

    public function testOrderedMultipleObservationsRoundTrip(): void
    {
        $codec = new DiagnosticBatchSnapshotCodec();
        $snapshot = new DiagnosticBatchSnapshot(
            'execution-1',
            'worker-task',
            array(
                new DiagnosticObservationSnapshot('execution-started', null, null, null),
                new DiagnosticObservationSnapshot('scope-close-started', null, null, null),
                new DiagnosticObservationSnapshot('scope-close-completed', 'succeeded', null, 'reusable'),
                new DiagnosticObservationSnapshot('execution-completed', 'succeeded', null, 'reusable'),
            ),
            0,
        );

        $roundTripped = $codec->decode($codec->encode($snapshot));

        self::assertSame(
            array('execution-started', 'scope-close-started', 'scope-close-completed', 'execution-completed'),
            array_map(
                static fn (DiagnosticObservationSnapshot $observation): string => $observation->type(),
                $roundTripped->observations(),
            ),
        );
    }

    public function testNullableFieldsAndDroppedCountRoundTrip(): void
    {
        $codec = new DiagnosticBatchSnapshotCodec();
        $snapshot = new DiagnosticBatchSnapshot(
            'execution-1',
            'scheduled-job',
            array(new DiagnosticObservationSnapshot('quarantine-required', null, null, null)),
            7,
        );

        $roundTripped = $codec->decode($codec->encode($snapshot));

        self::assertSame(7, $roundTripped->droppedObservationCount());
        self::assertNull($roundTripped->observations()[0]->outcome());
        self::assertNull($roundTripped->observations()[0]->errorType());
        self::assertNull($roundTripped->observations()[0]->reuseDecision());
    }

    public function testDiagnosticEntriesAndPrimitiveAttributeTypesRoundTrip(): void
    {
        $codec = new DiagnosticBatchSnapshotCodec();
        $snapshot = new DiagnosticBatchSnapshot(
            'execution-1',
            'http-request',
            array(),
            0,
            array(
                new DiagnosticEntrySnapshot(
                    'database',
                    'query',
                    array(
                        new DiagnosticEntryAttributeSnapshot('statement', 'select-user'),
                        new DiagnosticEntryAttributeSnapshot('duration_ms', 12),
                        new DiagnosticEntryAttributeSnapshot('sample_rate', 1.0),
                        new DiagnosticEntryAttributeSnapshot('cached', true),
                        new DiagnosticEntryAttributeSnapshot('tenant', null),
                    ),
                ),
            ),
            2,
        );

        $encoded = $codec->encode($snapshot);
        $roundTripped = $codec->decode($encoded);

        self::assertStringContainsString('"name":"sample_rate","value":1.0', $encoded);
        self::assertEquals($snapshot, $roundTripped);
        self::assertSame(2, $roundTripped->droppedDiagnosticEntryCount());
    }

    public function testLegacyVersionOneObservationOnlyPayloadDecodesWithEmptyDiagnosticEntries(): void
    {
        $snapshot = (new DiagnosticBatchSnapshotCodec())->decode(
            '{"version":1,"execution_identifier":"execution-1","execution_kind":"http-request","observations":[],"dropped_observation_count":0}',
        );

        self::assertSame(array(), $snapshot->diagnosticEntries());
        self::assertSame(0, $snapshot->droppedDiagnosticEntryCount());
    }

    public function testMalformedJsonIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Diagnostic batch snapshot payload must be valid JSON.');

        (new DiagnosticBatchSnapshotCodec())->decode('{');
    }

    public function testUnsupportedVersionIsRejected(): void
    {
        $this->expectDecodeRejection('{"version":3,"execution_identifier":"execution-1","execution_kind":"http-request","observations":[],"dropped_observation_count":0,"diagnostic_entries":[],"dropped_diagnostic_entry_count":0}');
    }

    public function testMissingRequiredFieldIsRejected(): void
    {
        $this->expectDecodeRejection('{"version":1,"execution_identifier":"execution-1","observations":[],"dropped_observation_count":0}');
    }

    public function testUnexpectedFieldIsRejected(): void
    {
        $this->expectDecodeRejection('{"version":1,"execution_identifier":"execution-1","execution_kind":"http-request","observations":[],"dropped_observation_count":0,"extra":true}');
    }

    public function testIncorrectPrimitiveTypeIsRejected(): void
    {
        $this->expectDecodeRejection('{"version":1,"execution_identifier":"execution-1","execution_kind":"http-request","observations":[],"dropped_observation_count":"0"}');
    }

    public function testMalformedObservationIsRejected(): void
    {
        $this->expectDecodeRejection('{"version":1,"execution_identifier":"execution-1","execution_kind":"http-request","observations":[{"type":"execution-started","outcome":null,"error_type":null}],"dropped_observation_count":0}');
    }

    public function testMalformedDiagnosticEntrySchemaIsRejected(): void
    {
        $this->expectDecodeRejection('{"version":2,"execution_identifier":"execution-1","execution_kind":"http-request","observations":[],"dropped_observation_count":0,"diagnostic_entries":[{"category":"database","name":"query"}],"dropped_diagnostic_entry_count":0}');
    }

    public function testMalformedDiagnosticAttributeValueIsRejected(): void
    {
        $this->expectDecodeRejection('{"version":2,"execution_identifier":"execution-1","execution_kind":"http-request","observations":[],"dropped_observation_count":0,"diagnostic_entries":[{"category":"database","name":"query","attributes":[{"name":"nested","value":[]}]}],"dropped_diagnostic_entry_count":0}');
    }

    public function testOversizedDiagnosticEntryCategoryIsRejectedDuringDecode(): void
    {
        $this->expectDecodeRejection($this->payloadWithEntry(
            category: str_repeat('c', DiagnosticEntry::MAX_CATEGORY_LENGTH + 1),
        ));
    }

    public function testOversizedDiagnosticEntryNameIsRejectedDuringDecode(): void
    {
        $this->expectDecodeRejection($this->payloadWithEntry(
            name: str_repeat('n', DiagnosticEntry::MAX_NAME_LENGTH + 1),
        ));
    }

    public function testExcessiveDiagnosticEntryAttributesAreRejectedDuringDecode(): void
    {
        $attributes = array_fill(
            0,
            DiagnosticEntry::MAX_ATTRIBUTE_COUNT + 1,
            array('name' => 'attribute', 'value' => 'value'),
        );

        $this->expectDecodeRejection($this->payloadWithEntry(attributes: $attributes));
    }

    public function testOversizedDiagnosticEntryAttributeNameIsRejectedDuringDecode(): void
    {
        $this->expectDecodeRejection($this->payloadWithEntry(attributes: array(
            array('name' => str_repeat('a', DiagnosticAttribute::MAX_NAME_LENGTH + 1), 'value' => 'value'),
        )));
    }

    public function testOversizedDiagnosticEntryAttributeStringValueIsRejectedDuringDecode(): void
    {
        $this->expectDecodeRejection($this->payloadWithEntry(attributes: array(
            array('name' => 'payload', 'value' => str_repeat('v', DiagnosticAttribute::MAX_STRING_VALUE_LENGTH + 1)),
        )));
    }

    public function testEncodeRejectsUnsupportedExecutionKind(): void
    {
        $this->expectEncodeRejection(new DiagnosticBatchSnapshot(
            'execution-1',
            'unsupported-kind',
            array(),
            0,
        ));
    }

    public function testEncodeRejectsUnsupportedObservationType(): void
    {
        $this->expectEncodeRejection(new DiagnosticBatchSnapshot(
            'execution-1',
            'http-request',
            array(new DiagnosticObservationSnapshot('unsupported-type', null, null, null)),
            0,
        ));
    }

    public function testEncodeRejectsUnsupportedNonNullOutcome(): void
    {
        $this->expectEncodeRejection(new DiagnosticBatchSnapshot(
            'execution-1',
            'http-request',
            array(new DiagnosticObservationSnapshot('execution-started', 'unsupported-outcome', null, null)),
            0,
        ));
    }

    public function testEncodeRejectsUnsupportedNonNullReuseDecision(): void
    {
        $this->expectEncodeRejection(new DiagnosticBatchSnapshot(
            'execution-1',
            'http-request',
            array(new DiagnosticObservationSnapshot('execution-started', null, null, 'unsupported-decision')),
            0,
        ));
    }

    private function expectDecodeRejection(string $payload): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new DiagnosticBatchSnapshotCodec())->decode($payload);
    }

    private function expectEncodeRejection(DiagnosticBatchSnapshot $snapshot): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new DiagnosticBatchSnapshotCodec())->encode($snapshot);
    }

    /**
     * @param list<array{name: string, value: string|int|float|bool|null}> $attributes
     */
    private function payloadWithEntry(
        string $category = 'database',
        string $name = 'query',
        array $attributes = array(array('name' => 'statement', 'value' => 'select-user')),
    ): string {
        return json_encode(
            array(
                'version' => 2,
                'execution_identifier' => 'execution-1',
                'execution_kind' => 'http-request',
                'observations' => array(),
                'dropped_observation_count' => 0,
                'diagnostic_entries' => array(array(
                    'category' => $category,
                    'name' => $name,
                    'attributes' => $attributes,
                )),
                'dropped_diagnostic_entry_count' => 0,
            ),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private function snapshot(): DiagnosticBatchSnapshot
    {
        return new DiagnosticBatchSnapshot(
            'execution-1',
            'http-request',
            array(new DiagnosticObservationSnapshot('execution-started', null, null, null)),
            0,
        );
    }
}
