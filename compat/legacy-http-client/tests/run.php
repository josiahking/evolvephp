<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Evolve\Bridge\LegacyHttp\LegacyCurlTransport;
use Evolve\Bridge\LegacyHttp\LegacyRemoteClient;
use Evolve\Bridge\LegacyHttp\LegacyRemoteClientAuthenticator;
use Evolve\Bridge\LegacyHttp\LegacyRemoteClientResult;
use Evolve\Bridge\LegacyHttp\LegacyRemoteCodec;
use Evolve\Bridge\LegacyHttp\LegacyRemoteInvocation;
use Evolve\Bridge\LegacyHttp\LegacyRemoteProtocol;
use Evolve\Bridge\LegacyHttp\LegacyRemoteTransport;
use Evolve\Bridge\LegacyHttp\LegacyRemoteTransportResponse;

$tests = [];

function test(string $name, callable $callback): void
{
    global $tests;
    $tests[$name] = $callback;
}

function same($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message : 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function ok($value, string $message = 'Expected truthy value.'): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

function throws(callable $callback, string $messagePart): void
{
    try {
        $callback();
    } catch (InvalidArgumentException $exception) {
        if (strpos($exception->getMessage(), $messagePart) === false) {
            throw new RuntimeException('Unexpected exception message: ' . $exception->getMessage());
        }

        return;
    }

    throw new RuntimeException('Expected InvalidArgumentException containing: ' . $messagePart);
}

function throwsAny(callable $callback, string $className, string $messagePart): void
{
    try {
        $callback();
    } catch (Throwable $throwable) {
        if (!$throwable instanceof $className) {
            throw new RuntimeException('Unexpected exception class: ' . get_class($throwable));
        }

        if (strpos($throwable->getMessage(), $messagePart) === false) {
            throw new RuntimeException('Unexpected exception message: ' . $throwable->getMessage());
        }

        return;
    }

    throw new RuntimeException('Expected ' . $className . ' containing: ' . $messagePart);
}

function fixture(): array
{
    $json = file_get_contents(__DIR__ . '/fixtures/protocol-v1.json');
    ok(is_string($json), 'Fixture must be readable.');
    $data = json_decode($json, true);
    ok(is_array($data), 'Fixture must decode.');

    return $data;
}

function modernizationFixture(): array
{
    $json = file_get_contents(__DIR__ . '/fixtures/modernization-cutover.json');
    ok(is_string($json), 'Modernization cutover fixture must be readable.');
    $data = json_decode($json, true);
    ok(is_array($data), 'Modernization cutover fixture must decode.');

    return $data;
}

function fixtureInvocation(): LegacyRemoteInvocation
{
    return new LegacyRemoteInvocation(
        'legacy.submit',
        'post',
        '/delegated',
        ['traceparent' => ['00-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb-01'], 'accept' => ['application/json']],
        '{"name":"Ada"}',
        ['items' => [['id' => 1], ['id' => 2]], 'active' => true],
        'request-1',
        'correlation-1',
        'legacy-app',
        'user-1',
        'tenant-1',
        'en_GB',
        'UTC',
        '2999-01-01T00:00:00+00:00',
        'idem-1',
        ['span' => 'span-1'],
    );
}

test('exact protocol constants', function (): void {
    same(1, LegacyRemoteProtocol::VERSION);
    same('POST', LegacyRemoteProtocol::HTTP_METHOD);
    same('application/vnd.evolve.bridge.remote.v1+json', LegacyRemoteProtocol::MEDIA_TYPE);
    same(65536, LegacyRemoteProtocol::MAX_BODY_BYTES);
});

test('request encoding matches protocol fixture', function (): void {
    $encoded = (new LegacyRemoteCodec())->encodeInvocation(fixtureInvocation());
    same(fixture()['request'], json_decode($encoded, true));
});

test('request encoding preserves full headers while delegation filtering remains separate', function (): void {
    $invocation = new LegacyRemoteInvocation(
        'legacy.submit',
        'post',
        '/delegated',
        ['X-Secret' => ['secret'], 'Content-Type' => ['application/json']],
        '',
        null,
        'request-1',
        'correlation-1',
        'legacy-app',
    );

    $encoded = (new LegacyRemoteCodec())->encodeInvocation($invocation);
    $decoded = json_decode($encoded, true);

    same(['content-type' => ['application/json'], 'x-secret' => ['secret']], $decoded['headers']);
    same(['content-type' => ['application/json']], $invocation->forwardableHeaders());
});

test('codec rejects oversized encoded invocations before transport', function (): void {
    $oversizedRequest = fixtureInvocation()->withDeadline('2999-01-01T00:00:00+00:00');
    $reflection = new ReflectionClass($oversizedRequest);
    $property = $reflection->getProperty('body');
    $property->setAccessible(true);
    $property->setValue($oversizedRequest, str_repeat('x', LegacyRemoteProtocol::MAX_BODY_BYTES));

    throwsAny(function () use ($oversizedRequest): void {
        (new LegacyRemoteCodec())->encodeInvocation($oversizedRequest);
    }, LengthException::class, 'maximum protocol size');
});

test('successful application response decoding', function (): void {
    $result = (new LegacyRemoteCodec())->decodeResult(json_encode(fixture()['application_response']));
    same('application', $result->outcome());
    same(202, $result->applicationStatus());
    same('{"ok":true}', $result->applicationBody());
    same(['content-type' => ['application/json']], $result->applicationHeaders());
    ok($result->isReusable());
    ok(!$result->requiresQuarantine());
});

test('Bridge-error response decoding preserves safe error', function (): void {
    $result = (new LegacyRemoteCodec())->decodeResult(json_encode(fixture()['bridge_error_response']));
    same('bridge_error', $result->outcome());
    same('protocol', $result->bridgeErrorKind());
    same('remote_bridge_invalid_request', $result->bridgeErrorCode());
    ok(!$result->bridgeErrorRetryable());
    ok($result->isReusable());
    ok(!$result->requiresQuarantine());
});

test('result invariants reject invalid status identifiers and quarantine state', function (): void {
    $codec = new LegacyRemoteCodec();

    foreach ([
        'blank request identifier' => ['request_id', ''],
        'blank correlation identifier' => ['correlation_id', ' '],
        'invalid outer status' => ['outer_status', 99],
    ] as $case) {
        $data = fixture()['application_response'];
        $data[$case[0]] = $case[1];
        throws(function () use ($codec, $data): void {
            $codec->decodeResult(json_encode($data));
        }, 'Remote Bridge');
    }

    $data = fixture()['application_response'];
    $data['application']['status'] = 700;
    throws(function () use ($codec, $data): void {
        $codec->decodeResult(json_encode($data));
    }, 'application status');

    $bridgeError = fixture()['bridge_error_response'];
    $bridgeError['bridge_error']['kind'] = 'reset_or_quarantine';
    throws(function () use ($codec, $bridgeError): void {
        $codec->decodeResult(json_encode($bridgeError));
    }, 'must not use reset or quarantine');

    $quarantineWithoutReset = fixture()['application_response'];
    $quarantineWithoutReset['requires_quarantine'] = true;
    throws(function () use ($codec, $quarantineWithoutReset): void {
        $codec->decodeResult(json_encode($quarantineWithoutReset));
    }, 'quarantine results require');

    $resetWithoutQuarantine = fixture()['application_response'];
    $resetWithoutQuarantine['bridge_error'] = ['kind' => 'reset_or_quarantine', 'code' => 'embedded_process_quarantined', 'message' => 'Quarantined.', 'retryable' => false];
    throws(function () use ($codec, $resetWithoutQuarantine): void {
        $codec->decodeResult(json_encode($resetWithoutQuarantine));
    }, 'quarantined reset state');
});

test('malformed oversized unsupported and wrong protocol JSON rejected', function (): void {
    $codec = new LegacyRemoteCodec();
    throws(function () use ($codec): void {
        $codec->decodeResult('{"broken"');
    }, 'malformed');
    throwsAny(function () use ($codec): void {
        $codec->decodeResult(str_repeat(' ', LegacyRemoteProtocol::MAX_BODY_BYTES + 1));
    }, LengthException::class, 'maximum protocol size');
    $bad = fixture()['application_response'];
    $bad['version'] = 2;
    throws(function () use ($codec, $bad): void {
        $codec->decodeResult(json_encode($bad));
    }, 'version');
    $bad = fixture()['application_response'];
    $bad['protocol'] = 'application/json';
    throws(function () use ($codec, $bad): void {
        $codec->decodeResult(json_encode($bad));
    }, 'protocol identifier');
});

test('invalid transport values and non-finite floats rejected', function (): void {
    throws(function (): void {
        new LegacyRemoteInvocation('op', 'GET', '/x', [], '', new stdClass(), 'r', 'c', 'caller');
    }, 'transport-unsafe');
    throws(function (): void {
        new LegacyRemoteInvocation('op', 'GET', '/x', [], '', INF, 'r', 'c', 'caller');
    }, 'non-finite float');
    throws(function (): void {
        new LegacyRemoteInvocation('op', 'GET', '/x', ["bad\nname" => ['x']], '', null, 'r', 'c', 'caller');
    }, 'header names');
    throws(function (): void {
        new LegacyRemoteInvocation('op', 'GET', '/x', ['x-test' => ["bad\r\nvalue"]], '', null, 'r', 'c', 'caller');
    }, 'header values');
});

test('client validation, auth, transport, timeout and protocol failures', function (): void {
    throws(function (): void {
        new LegacyRemoteClient('ftp://example.test', new RecordingTransport(), new NullAuthenticator());
    }, 'endpoint');

    $expiredTransport = new RecordingTransport();
    $expired = fixtureInvocation()->withDeadline('2000-01-01T00:00:00+00:00');
    $expiredResult = (new LegacyRemoteClient('https://example.test/bridge', $expiredTransport, new NullAuthenticator()))->invoke($expired);
    same('timeout', $expiredResult->failureKind());
    same(0, $expiredTransport->calls);

    $auth = new RecordingAuthenticator(['x-auth-token' => ['secret']]);
    $transport = new RecordingTransport(new LegacyRemoteTransportResponse(200, ['content-type' => [LegacyRemoteProtocol::MEDIA_TYPE]], json_encode(fixture()['application_response'])));
    $result = (new LegacyRemoteClient('https://example.test/bridge', $transport, $auth))->invoke(fixtureInvocation());
    ok($result->received());
    same(1, $auth->calls);
    same(1, $transport->calls);
    same('POST', $transport->method);
    same('https://example.test/bridge', $transport->endpoint);
    same(LegacyRemoteProtocol::MEDIA_TYPE, $transport->headers['content-type'][0]);
    same('secret', $transport->headers['x-auth-token'][0]);

    $reserved = (new LegacyRemoteClient('https://example.test/bridge', new RecordingTransport(), new RecordingAuthenticator(['content-type' => ['text/plain']])))->invoke(fixtureInvocation());
    same('authentication', $reserved->failureKind());

    $redirect = (new LegacyRemoteClient('https://example.test/bridge', new RecordingTransport(new LegacyRemoteTransportResponse(302, ['content-type' => [LegacyRemoteProtocol::MEDIA_TYPE]], '')), new NullAuthenticator()))->invoke(fixtureInvocation());
    same('remote_bridge_redirect_response', $redirect->failureCode());

    $media = (new LegacyRemoteClient('https://example.test/bridge', new RecordingTransport(new LegacyRemoteTransportResponse(200, ['content-type' => ['text/plain']], '{}')), new NullAuthenticator()))->invoke(fixtureInvocation());
    same('remote_bridge_unsupported_media_type', $media->failureCode());

    $status = fixture()['bridge_error_response'];
    $status['outer_status'] = 401;
    $mismatch = (new LegacyRemoteClient('https://example.test/bridge', new RecordingTransport(new LegacyRemoteTransportResponse(400, ['content-type' => [LegacyRemoteProtocol::MEDIA_TYPE]], json_encode($status))), new NullAuthenticator()))->invoke(fixtureInvocation());
    same('remote_bridge_status_mismatch', $mismatch->failureCode());

    $request = fixture()['application_response'];
    $request['request_id'] = 'other';
    $requestMismatch = (new LegacyRemoteClient('https://example.test/bridge', new RecordingTransport(new LegacyRemoteTransportResponse(200, ['content-type' => [LegacyRemoteProtocol::MEDIA_TYPE]], json_encode($request))), new NullAuthenticator()))->invoke(fixtureInvocation());
    same('remote_bridge_request_mismatch', $requestMismatch->failureCode());

    $correlation = fixture()['application_response'];
    $correlation['correlation_id'] = 'other';
    $correlationMismatch = (new LegacyRemoteClient('https://example.test/bridge', new RecordingTransport(new LegacyRemoteTransportResponse(200, ['content-type' => [LegacyRemoteProtocol::MEDIA_TYPE]], json_encode($correlation))), new NullAuthenticator()))->invoke(fixtureInvocation());
    same('remote_bridge_correlation_mismatch', $correlationMismatch->failureCode());

    $oversized = (new LegacyRemoteClient('https://example.test/bridge', new RecordingTransport(new LegacyRemoteTransportResponse(200, ['content-type' => [LegacyRemoteProtocol::MEDIA_TYPE]], str_repeat('x', LegacyRemoteProtocol::MAX_BODY_BYTES + 1))), new NullAuthenticator()))->invoke(fixtureInvocation());
    same('remote_bridge_response_too_large', $oversized->failureCode());

    $timeout = (new LegacyRemoteClient('https://example.test/bridge', new TimeoutTransport(), new NullAuthenticator()))->invoke(fixtureInvocation());
    same('timeout', $timeout->failureKind());

    $uncertain = (new LegacyRemoteClient('https://example.test/bridge', new UncertainTransport(), new NullAuthenticator()))->invoke(fixtureInvocation());
    same('uncertain_outcome', $uncertain->failureKind());

    $throwingTransport = new ThrowingTransport();
    $throwing = (new LegacyRemoteClient('https://example.test/bridge', $throwingTransport, new NullAuthenticator()))->invoke(fixtureInvocation());
    same('uncertain_outcome', $throwing->failureKind());
    same('remote_bridge_outcome_uncertain', $throwing->failureCode());
    same(1, $throwingTransport->calls);

    $oversizedRequestTransport = new RecordingTransport();
    $oversizedRequest = fixtureInvocation()->withDeadline('2999-01-01T00:00:00+00:00');
    $reflection = new ReflectionClass($oversizedRequest);
    $property = $reflection->getProperty('body');
    $property->setAccessible(true);
    $property->setValue($oversizedRequest, str_repeat('x', LegacyRemoteProtocol::MAX_BODY_BYTES));
    $oversizedRequestResult = (new LegacyRemoteClient('https://example.test/bridge', $oversizedRequestTransport, new NullAuthenticator()))->invoke($oversizedRequest);
    same('remote_bridge_request_too_large', $oversizedRequestResult->failureCode());
    same(0, $oversizedRequestTransport->calls);
});

test('quarantine fields are preserved', function (): void {
    $data = fixture()['application_response'];
    $data['reusable'] = false;
    $data['requires_quarantine'] = true;
    $data['bridge_error'] = ['kind' => 'reset_or_quarantine', 'code' => 'embedded_process_quarantined', 'message' => 'Quarantined.', 'retryable' => false];
    $result = (new LegacyRemoteCodec())->decodeResult(json_encode($data));
    ok(!$result->isReusable());
    ok($result->requiresQuarantine());
    same('reset_or_quarantine', $result->bridgeErrorKind());
});

test('cURL transport exposes safe configuration policy', function (): void {
    $options = LegacyCurlTransport::defaultOptions(2.5, 7.5);
    same(0, $options['follow_redirects']);
    same(1, $options['verify_peer']);
    same(2, $options['verify_host']);
    same(2500, $options['connect_timeout_ms']);
    same(7500, $options['request_timeout_ms']);
});

test('modernization cutover vector encodes and consumes canonical application result', function (): void {
    $fixture = modernizationFixture();
    $transport = new ModernizationCutoverTransport($fixture);
    $client = new LegacyRemoteClient('https://bridge.example.test/evolve-remote', $transport, new NullAuthenticator());
    $result = $client->invoke(new LegacyRemoteInvocation(
        $fixture['operation'],
        $fixture['method'],
        $fixture['target'],
        $fixture['headers'],
        $fixture['body'],
        $fixture['payload'],
        $fixture['request_id'],
        $fixture['correlation_id'],
        $fixture['caller_id'],
        $fixture['principal_id'],
        $fixture['tenant_id'],
        $fixture['locale'],
        $fixture['timezone'],
        $fixture['deadline'],
        $fixture['idempotency_key'],
        $fixture['trace'],
    ));

    ok($result->received());
    same('application', $result->outcome());
    same($fixture['request_id'], $result->requestIdentifier());
    same($fixture['correlation_id'], $result->correlationIdentifier());
    same($fixture['expected_application']['status'], $result->applicationStatus());
    same($fixture['expected_application']['headers'], $result->applicationHeaders());
    same($fixture['expected_result'], json_decode($result->applicationBody(), true));
    same(1, $transport->calls);
    same('POST', $transport->method);
    same('https://bridge.example.test/evolve-remote', $transport->endpoint);
    same(LegacyRemoteProtocol::MEDIA_TYPE, $transport->headers['content-type'][0]);
    same($fixture['request_id'], $transport->headers['x-request-id'][0]);
    same($fixture['correlation_id'], $transport->headers['x-correlation-id'][0]);
    same($fixture['idempotency_key'], $transport->headers['x-idempotency-key'][0]);
    same($fixture['operation'], $transport->encoded['operation']);
    same($fixture['payload']['invoice_ids'], $transport->encoded['payload']['invoice_ids']);
    same($fixture['payload']['amounts'], $transport->encoded['payload']['amounts']);
    same($fixture['payload']['currency'], $transport->encoded['payload']['currency']);
    same($fixture['headers'], $transport->encoded['headers']);
});

final class NullAuthenticator implements LegacyRemoteClientAuthenticator
{
    public function authenticationHeaders(LegacyRemoteInvocation $invocation): array
    {
        return [];
    }
}

final class RecordingAuthenticator implements LegacyRemoteClientAuthenticator
{
    public $calls = 0;
    private $headers;

    public function __construct(array $headers)
    {
        $this->headers = $headers;
    }

    public function authenticationHeaders(LegacyRemoteInvocation $invocation): array
    {
        ++$this->calls;

        return $this->headers;
    }
}

final class RecordingTransport implements LegacyRemoteTransport
{
    public $calls = 0;
    public $method;
    public $endpoint;
    public $headers = [];
    public $body;
    private $response;

    public function __construct(?LegacyRemoteTransportResponse $response = null)
    {
        $this->response = $response ?: new LegacyRemoteTransportResponse(200, ['content-type' => [LegacyRemoteProtocol::MEDIA_TYPE]], json_encode(fixture()['application_response']));
    }

    public function send(string $method, string $endpoint, array $headers, string $body, float $connectTimeoutSeconds, float $requestTimeoutSeconds): LegacyRemoteTransportResponse
    {
        ++$this->calls;
        $this->method = $method;
        $this->endpoint = $endpoint;
        $this->headers = $headers;
        $this->body = $body;

        return $this->response;
    }
}

final class TimeoutTransport implements LegacyRemoteTransport
{
    public $calls = 0;

    public function send(string $method, string $endpoint, array $headers, string $body, float $connectTimeoutSeconds, float $requestTimeoutSeconds): LegacyRemoteTransportResponse
    {
        ++$this->calls;

        throw LegacyRemoteClientResult::timeoutFailure();
    }
}

final class UncertainTransport implements LegacyRemoteTransport
{
    public $calls = 0;

    public function send(string $method, string $endpoint, array $headers, string $body, float $connectTimeoutSeconds, float $requestTimeoutSeconds): LegacyRemoteTransportResponse
    {
        ++$this->calls;

        throw LegacyRemoteClientResult::uncertainTransportFailure();
    }
}

final class ThrowingTransport implements LegacyRemoteTransport
{
    public $calls = 0;

    public function send(string $method, string $endpoint, array $headers, string $body, float $connectTimeoutSeconds, float $requestTimeoutSeconds): LegacyRemoteTransportResponse
    {
        ++$this->calls;

        throw new RuntimeException('network state unknown');
    }
}

final class ModernizationCutoverTransport implements LegacyRemoteTransport
{
    public $calls = 0;
    public $method;
    public $endpoint;
    public $headers = [];
    public $encoded = [];
    private $fixture;

    public function __construct(array $fixture)
    {
        $this->fixture = $fixture;
    }

    public function send(string $method, string $endpoint, array $headers, string $body, float $connectTimeoutSeconds, float $requestTimeoutSeconds): LegacyRemoteTransportResponse
    {
        ++$this->calls;
        $this->method = $method;
        $this->endpoint = $endpoint;
        $this->headers = $headers;
        $decoded = json_decode($body, true);
        ok(is_array($decoded), 'Modernization cutover request must decode.');
        $this->encoded = $decoded;

        same($this->fixture['operation'], $decoded['operation']);
        same($this->fixture['method'], $decoded['method']);
        same($this->fixture['target'], $decoded['target']);
        same($this->fixture['request_id'], $decoded['request_id']);
        same($this->fixture['correlation_id'], $decoded['correlation_id']);
        same($this->fixture['caller_id'], $decoded['caller_id']);
        same($this->fixture['principal_id'], $decoded['principal_id']);
        same($this->fixture['tenant_id'], $decoded['tenant_id']);

        return new LegacyRemoteTransportResponse(
            200,
            ['content-type' => [LegacyRemoteProtocol::MEDIA_TYPE]],
            json_encode([
                'application' => [
                    'body' => json_encode($this->fixture['expected_result']),
                    'headers' => $this->fixture['expected_application']['headers'],
                    'status' => $this->fixture['expected_application']['status'],
                ],
                'bridge_error' => null,
                'correlation_id' => $this->fixture['correlation_id'],
                'outcome' => 'application',
                'outer_status' => 200,
                'protocol' => LegacyRemoteProtocol::MEDIA_TYPE,
                'requires_quarantine' => false,
                'request_id' => $this->fixture['request_id'],
                'reusable' => true,
                'version' => LegacyRemoteProtocol::VERSION,
            ]),
        );
    }
}

$failures = 0;

foreach ($tests as $name => $callback) {
    try {
        $callback();
        echo '[OK] ' . $name . PHP_EOL;
    } catch (Throwable $throwable) {
        ++$failures;
        echo '[FAIL] ' . $name . ': ' . $throwable->getMessage() . PHP_EOL;
    }
}

if ($failures > 0) {
    echo $failures . ' legacy remote client test(s) failed.' . PHP_EOL;
    exit(1);
}

echo count($tests) . ' legacy remote client tests passed.' . PHP_EOL;
