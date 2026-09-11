<?php

declare(strict_types=1);

namespace Evolve\Bridge\Remote\Tests\Unit;

use Evolve\Bridge\Contracts\BridgeError;
use Evolve\Bridge\Contracts\BridgeErrorKind;
use Evolve\Bridge\Remote\RemoteBridgeCodec;
use Evolve\Bridge\Remote\RemoteBridgeInvocation;
use Evolve\Bridge\Remote\RemoteBridgeProtocol;
use Evolve\Bridge\Remote\RemoteBridgeResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class RemoteBridgeCodecTest extends TestCase
{
    public function test_protocol_v1_constants_define_media_method_size_and_header_policy(): void
    {
        $protocol = new ReflectionClass(RemoteBridgeProtocol::class);
        $constants = $protocol->getConstants();

        self::assertSame(1, $constants['VERSION']);
        self::assertSame('application/vnd.evolve.bridge.remote.v1+json', $constants['MEDIA_TYPE']);
        self::assertSame('POST', $constants['HTTP_METHOD']);
        self::assertSame(65536, $constants['MAX_BODY_BYTES']);
        self::assertSame(
            ['accept', 'content-type', 'traceparent', 'tracestate', 'x-correlation-id', 'x-idempotency-key', 'x-request-id'],
            $constants['FORWARDED_REQUEST_HEADERS'],
        );
    }

    public function test_valid_invocation_json_round_trips_and_encodes_deterministically(): void
    {
        $codec = new RemoteBridgeCodec();
        $invocation = $this->invocation();

        $encoded = $codec->encodeInvocation($invocation);

        self::assertSame($encoded, $codec->encodeInvocation($invocation));
        self::assertEquals($invocation, $codec->decodeInvocation($encoded));
        self::assertSame('request-1', $codec->decodeInvocation($encoded)->requestIdentifier());
        self::assertSame('correlation-1', $codec->decodeInvocation($encoded)->correlationIdentifier());
    }

    public function test_valid_result_json_round_trips_with_application_status_distinct_from_outer_status(): void
    {
        $codec = new RemoteBridgeCodec();
        $result = RemoteBridgeResult::applicationResponse(
            'request-1',
            'correlation-1',
            202,
            ['content-type' => ['application/json']],
            '{"ok":true}',
        );

        $encoded = $codec->encodeResult($result);
        $decoded = $codec->decodeResult($encoded);

        self::assertSame($encoded, $codec->encodeResult($result));
        self::assertSame('application', $decoded->outcome());
        self::assertSame(200, $decoded->outerStatus());
        self::assertSame(202, $decoded->applicationStatus());
        self::assertSame('correlation-1', $decoded->correlationIdentifier());
    }

    public function test_completed_quarantined_application_result_round_trips_with_response_error_and_state(): void
    {
        $codec = new RemoteBridgeCodec();
        $result = RemoteBridgeResult::applicationResponse(
            'request-1',
            'correlation-1',
            202,
            ['content-type' => ['application/json']],
            '{"ok":true}',
            new BridgeError(BridgeErrorKind::ResetOrQuarantine, 'embedded_process_quarantined', 'Cleanup failed safely.', false),
            false,
            true,
        );

        $decoded = $codec->decodeResult($codec->encodeResult($result));

        self::assertSame('application', $decoded->outcome());
        self::assertSame(202, $decoded->applicationStatus());
        self::assertSame('{"ok":true}', $decoded->applicationBody());
        self::assertSame(BridgeErrorKind::ResetOrQuarantine, $decoded->bridgeError()?->kind());
        self::assertFalse($decoded->isReusable());
        self::assertTrue($decoded->requiresQuarantine());
    }

    public function test_application_payload_body_and_response_data_may_contain_json_safe_security_words(): void
    {
        $codec = new RemoteBridgeCodec();
        $invocation = new RemoteBridgeInvocation(
            operation: '/delegated',
            method: 'POST',
            target: '/delegated',
            headers: ['content-type' => ['application/json']],
            body: '{"password_reset_token":"token","trace":"stack"}',
            payload: ['token' => 'password_reset_token', 'trace' => ['stack' => 'C:\\application\\path.txt']],
            requestIdentifier: 'request-1',
            correlationIdentifier: 'correlation-1',
            callerIdentifier: 'caller-1',
        );
        $result = RemoteBridgeResult::applicationResponse(
            'request-1',
            'correlation-1',
            200,
            ['content-type' => ['application/json']],
            ['message' => 'password token trace stack C:\\application\\path.txt'],
        );

        self::assertEquals($invocation, $codec->decodeInvocation($codec->encodeInvocation($invocation)));
        self::assertSame(
            ['message' => 'password token trace stack C:\\application\\path.txt'],
            $codec->decodeResult($codec->encodeResult($result))->applicationBody(),
        );
    }

    public function test_invocation_and_result_decode_reject_missing_or_wrong_protocol_identifier(): void
    {
        $codec = new RemoteBridgeCodec();
        $invocation = json_decode($codec->encodeInvocation($this->invocation()), true, 512, JSON_THROW_ON_ERROR);
        unset($invocation['protocol']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote Bridge protocol identifier is not supported.');
        $codec->decodeInvocation(json_encode($invocation, JSON_THROW_ON_ERROR));
    }

    public function test_result_decode_rejects_wrong_protocol_identifier(): void
    {
        $codec = new RemoteBridgeCodec();
        $result = json_decode($codec->encodeResult(RemoteBridgeResult::applicationResponse('request-1', 'correlation-1', 200, [], null)), true, 512, JSON_THROW_ON_ERROR);
        $result['protocol'] = 'application/json';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote Bridge protocol identifier is not supported.');
        $codec->decodeResult(json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_result_decode_rejects_inconsistent_application_outer_status(): void
    {
        $codec = new RemoteBridgeCodec();
        $result = json_decode($codec->encodeResult(RemoteBridgeResult::applicationResponse('request-1', 'correlation-1', 200, [], null)), true, 512, JSON_THROW_ON_ERROR);
        $result['outer_status'] = 299;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote Bridge application results require outer status 200.');
        $codec->decodeResult(json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function test_bridge_error_mapping_preserves_only_safe_public_error_fields(): void
    {
        $codec = new RemoteBridgeCodec();
        $error = new BridgeError(BridgeErrorKind::Authorization, 'denied', 'Operation is not allowed.', false);

        $decoded = $codec->decodeResult($codec->encodeResult(RemoteBridgeResult::error(
            'request-1',
            'correlation-1',
            403,
            $error,
        )));

        self::assertSame('bridge_error', $decoded->outcome());
        self::assertSame(403, $decoded->outerStatus());
        self::assertSame(BridgeErrorKind::Authorization, $decoded->bridgeError()->kind());
        self::assertSame('denied', $decoded->bridgeError()->code());
        self::assertSame('Operation is not allowed.', $decoded->bridgeError()->message());
        self::assertFalse($decoded->bridgeError()->isRetryable());
    }

    public function test_bridge_error_result_is_reusable_and_not_quarantined(): void
    {
        $result = RemoteBridgeResult::error(
            'request-1',
            'correlation-1',
            403,
            new BridgeError(BridgeErrorKind::Authorization, 'denied', 'Operation is not allowed.', false),
        );

        self::assertTrue($result->isReusable());
        self::assertFalse($result->requiresQuarantine());
    }

    public function test_bridge_error_decode_rejects_non_reusable_wire_state(): void
    {
        $codec = new RemoteBridgeCodec();
        $data = $this->bridgeErrorResultData();
        $data['reusable'] = false;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote Bridge protocol failures must be reusable.');
        $codec->decodeResult(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function test_bridge_error_decode_rejects_quarantined_wire_state(): void
    {
        $codec = new RemoteBridgeCodec();
        $data = $this->bridgeErrorResultData();
        $data['requires_quarantine'] = true;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote Bridge protocol failures must not claim quarantine.');
        $codec->decodeResult(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function test_bridge_error_decode_rejects_non_boolean_wire_state_fields(): void
    {
        $codec = new RemoteBridgeCodec();

        foreach (['reusable', 'requires_quarantine'] as $field) {
            $data = $this->bridgeErrorResultData();
            $data[$field] = 'false';

            try {
                $codec->decodeResult(json_encode($data, JSON_THROW_ON_ERROR));
                self::fail('Invalid bridge_error state field was accepted: ' . $field);
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Remote Bridge ' . $field . ' must be a boolean.', $exception->getMessage());
            }
        }
    }

    public function test_bridge_error_decode_rejects_non_null_application_field(): void
    {
        $codec = new RemoteBridgeCodec();
        $data = $this->bridgeErrorResultData();
        $data['application'] = [
            'body' => '{"ok":true}',
            'headers' => ['content-type' => ['application/json']],
            'status' => 200,
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote Bridge error results must not include an application response.');
        $codec->decodeResult(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function test_bridge_error_decode_accepts_null_or_omitted_application_field(): void
    {
        $codec = new RemoteBridgeCodec();
        $withNullApplication = $this->bridgeErrorResultData();
        $withNullApplication['application'] = null;
        $withoutApplication = $this->bridgeErrorResultData();
        unset($withoutApplication['application']);

        foreach ([$withNullApplication, $withoutApplication] as $data) {
            $decoded = $codec->decodeResult(json_encode($data, JSON_THROW_ON_ERROR));

            self::assertSame('bridge_error', $decoded->outcome());
            self::assertNull($decoded->applicationStatus());
            self::assertSame([], $decoded->applicationHeaders());
            self::assertNull($decoded->applicationBody());
            self::assertSame(BridgeErrorKind::Authorization, $decoded->bridgeError()?->kind());
            self::assertTrue($decoded->isReusable());
            self::assertFalse($decoded->requiresQuarantine());
        }
    }

    public function test_application_response_rejects_non_quarantine_bridge_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote Bridge application errors require quarantined reset state.');
        RemoteBridgeResult::applicationResponse(
            'request-1',
            'correlation-1',
            200,
            [],
            null,
            new BridgeError(BridgeErrorKind::Authorization, 'denied', 'Operation is not allowed.', false),
        );
    }

    public function test_application_response_rejects_non_reusable_without_quarantine(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote Bridge non-quarantined results must be reusable.');
        RemoteBridgeResult::applicationResponse(
            'request-1',
            'correlation-1',
            200,
            [],
            null,
            null,
            false,
            false,
        );
    }

    public function test_malformed_json_oversized_input_and_unsupported_versions_fail_safely(): void
    {
        $codec = new RemoteBridgeCodec();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote Bridge JSON is malformed.');
        $codec->decodeInvocation('{broken');
    }

    public function test_oversized_input_fails_before_decoding(): void
    {
        $codec = new RemoteBridgeCodec();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote Bridge JSON exceeds the maximum protocol size.');
        $codec->decodeInvocation(str_repeat(' ', RemoteBridgeProtocol::MAX_BODY_BYTES + 1));
    }

    public function test_unsupported_protocol_version_fails_safely(): void
    {
        $codec = new RemoteBridgeCodec();
        $encoded = str_replace('"version":1', '"version":2', $codec->encodeInvocation($this->invocation()));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Remote Bridge protocol version is not supported.');
        $codec->decodeInvocation($encoded);
    }

    public function test_missing_required_fields_deadline_idempotency_trace_and_transport_values_are_validated(): void
    {
        $codec = new RemoteBridgeCodec();

        foreach ([
            'missing operation' => ['operation'],
            'blank operation' => ['operation', ''],
            'bad deadline' => ['deadline', 'tomorrow'],
            'bad idempotency key' => ['idempotency_key', str_repeat('x', 129)],
            'bad trace key' => ['trace', ['bad key' => 'value']],
            'bad trace value' => ['trace', ['traceparent' => str_repeat('x', 513)]],
        ] as $case) {
            $data = json_decode($codec->encodeInvocation($this->invocation()), true, 512, JSON_THROW_ON_ERROR);
            if (count($case) === 1) {
                unset($data[$case[0]]);
            } else {
                $data[$case[0]] = $case[1];
            }

            try {
                $codec->decodeInvocation(json_encode($data, JSON_THROW_ON_ERROR));
                self::fail('Invalid invocation was accepted: ' . $case[0]);
            } catch (InvalidArgumentException $exception) {
                self::assertStringStartsWith('Remote Bridge', $exception->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        new RemoteBridgeInvocation(
            operation: '/delegated',
            method: 'GET',
            target: '/delegated',
            headers: [],
            body: '',
            payload: new \stdClass(),
            requestIdentifier: 'request-1',
            correlationIdentifier: 'correlation-1',
            callerIdentifier: 'caller-1',
        );
    }

    public function test_result_encoding_rejects_php_specific_debug_objects(): void
    {
        $codec = new RemoteBridgeCodec();
        $this->expectException(InvalidArgumentException::class);
        $unsafe = RemoteBridgeResult::applicationResponse('request-1', 'correlation-1', 200, [], [
            'debug' => new \RuntimeException('do not serialize objects'),
        ]);
        $codec->encodeResult($unsafe);
    }

    public function test_optional_additive_fields_are_ignored_by_v1_decode_policy(): void
    {
        $codec = new RemoteBridgeCodec();
        $data = json_decode($codec->encodeInvocation($this->invocation()), true, 512, JSON_THROW_ON_ERROR);
        $data['future_optional'] = ['ignored' => true];

        self::assertEquals($this->invocation(), $codec->decodeInvocation(json_encode($data, JSON_THROW_ON_ERROR)));
    }

    private function invocation(): RemoteBridgeInvocation
    {
        return new RemoteBridgeInvocation(
            operation: '/delegated',
            method: 'PATCH',
            target: '/delegated?ok=1',
            headers: ['content-type' => ['application/json'], 'x-secret' => ['removed']],
            body: '{"name":"Evolve"}',
            payload: ['safe' => true],
            requestIdentifier: 'request-1',
            correlationIdentifier: 'correlation-1',
            callerIdentifier: 'caller-1',
            principalIdentifier: 'principal-1',
            tenantIdentifier: 'tenant-1',
            locale: 'en_US',
            timezone: 'UTC',
            deadline: '2999-01-01T00:00:00+00:00',
            idempotencyKey: 'idem-1',
            trace: ['traceparent' => '00-00000000000000000000000000000000-0000000000000000-01'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function bridgeErrorResultData(): array
    {
        $codec = new RemoteBridgeCodec();

        return json_decode($codec->encodeResult(RemoteBridgeResult::error(
            'request-1',
            'correlation-1',
            403,
            new BridgeError(BridgeErrorKind::Authorization, 'denied', 'Operation is not allowed.', false),
        )), true, 512, JSON_THROW_ON_ERROR);
    }
}
