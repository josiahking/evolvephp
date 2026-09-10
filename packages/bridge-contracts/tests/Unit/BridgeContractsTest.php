<?php

declare(strict_types=1);

namespace Evolve\Bridge\Contracts\Tests\Unit;

use Closure;
use Evolve\Bridge\Contracts\BridgeContext;
use Evolve\Bridge\Contracts\BridgeError;
use Evolve\Bridge\Contracts\BridgeErrorKind;
use Evolve\Bridge\Contracts\BridgeRequest;
use Evolve\Bridge\Contracts\BridgeResponse;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Throwable;

final class BridgeContractsTest extends TestCase
{
    public function test_bridge_error_kind_vocabulary_is_exact(): void
    {
        $actualCases = iterator_to_array((static function (): \Generator {
            foreach (BridgeErrorKind::cases() as $case) {
                yield $case->name => $case->value;
            }
        })());

        $expectedCases = [
            'Configuration' => 'configuration',
            'Compatibility' => 'compatibility',
            'Authentication' => 'authentication',
            'Authorization' => 'authorization',
            'Validation' => 'validation',
            'Translation' => 'translation',
            'BootOrReadiness' => 'boot_or_readiness',
            'Execution' => 'execution',
            'Timeout' => 'timeout',
            'Cancellation' => 'cancellation',
            'Transport' => 'transport',
            'Protocol' => 'protocol',
            'UncertainOutcome' => 'uncertain_outcome',
            'ResetOrQuarantine' => 'reset_or_quarantine',
            'DependencyUnavailable' => 'dependency_unavailable',
        ];

        self::assertSame($expectedCases, $actualCases);
    }

    public function test_context_preserves_valid_declaration_exactly(): void
    {
        $context = new BridgeContext(
            ' request-1 ',
            ' correlation-1 ',
            ' principal-1 ',
            ' tenant-1 ',
            ' en_GB ',
            ' Africa/Lagos ',
        );

        self::assertSame(' request-1 ', $context->requestIdentifier());
        self::assertSame(' correlation-1 ', $context->correlationIdentifier());
        self::assertSame(' principal-1 ', $context->principalIdentifier());
        self::assertSame(' tenant-1 ', $context->tenantIdentifier());
        self::assertSame(' en_GB ', $context->locale());
        self::assertSame(' Africa/Lagos ', $context->timezone());
    }

    public function test_context_rejects_blank_required_identifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BridgeContext('', 'correlation-1');
    }

    public function test_context_rejects_blank_optional_identifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BridgeContext('request-1', 'correlation-1', '   ');
    }

    public function test_request_preserves_operation_context_and_payload_exactly(): void
    {
        $context = new BridgeContext('request-1', 'correlation-1');
        $payload = ['answer' => 42, 'nested' => ['yes' => true]];

        $request = new BridgeRequest(' operation.name ', $context, $payload);

        self::assertSame(' operation.name ', $request->operation());
        self::assertSame($context, $request->context());
        self::assertSame($payload, $request->payload());
    }

    public function test_request_rejects_blank_operation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BridgeRequest("\t\n", new BridgeContext('request-1', 'correlation-1'), null);
    }

    public function test_request_accepts_scalar_null_and_array_payloads(): void
    {
        $context = new BridgeContext('request-1', 'correlation-1');

        foreach ([null, true, false, 1, 1.5, 'value', ['nested' => [null, false, 3, 4.5, 'value']]] as $payload) {
            self::assertSame($payload, (new BridgeRequest('operation', $context, $payload))->payload());
        }
    }

    public function test_request_rejects_nested_objects(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BridgeRequest('operation', new BridgeContext('request-1', 'correlation-1'), ['object' => new \stdClass()]);
    }

    public function test_request_rejects_resources(): void
    {
        $resource = fopen(__FILE__, 'rb');
        self::assertIsResource($resource);

        try {
            $this->expectException(InvalidArgumentException::class);

            new BridgeRequest('operation', new BridgeContext('request-1', 'correlation-1'), $resource);
        } finally {
            fclose($resource);
        }
    }

    public function test_request_rejects_non_finite_floats(): void
    {
        foreach ([INF, -INF, NAN] as $payload) {
            try {
                new BridgeRequest('operation', new BridgeContext('request-1', 'correlation-1'), $payload);
                self::fail('Expected non-finite float payload rejection.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_request_rejects_recursive_or_excessively_deep_structures_safely(): void
    {
        $recursive = [];
        $recursive['self'] = &$recursive;

        try {
            new BridgeRequest('operation', new BridgeContext('request-1', 'correlation-1'), $recursive);
            self::fail('Expected recursive payload rejection.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $deep = 'leaf';

        for ($i = 0; $i < 80; $i++) {
            $deep = [$deep];
        }

        $this->expectException(InvalidArgumentException::class);

        new BridgeRequest('operation', new BridgeContext('request-1', 'correlation-1'), $deep);
    }

    public function test_successful_response_with_null_result_remains_successful(): void
    {
        $response = BridgeResponse::success(null);

        self::assertTrue($response->isSuccessful());
        self::assertNull($response->result());
        self::assertNull($response->error());
    }

    public function test_successful_response_validates_result(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BridgeResponse::success(Closure::fromCallable(static fn(): null => null));
    }

    public function test_failure_contains_exact_error_and_no_successful_result_semantics(): void
    {
        $error = new BridgeError(BridgeErrorKind::Validation, ' validation.code ', ' Validation failed. ', false);
        $response = BridgeResponse::failure($error);

        self::assertFalse($response->isSuccessful());
        self::assertNull($response->result());
        self::assertSame($error, $response->error());
    }

    public function test_error_rejects_blank_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BridgeError(BridgeErrorKind::Configuration, ' ', 'message', false);
    }

    public function test_error_rejects_blank_message(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BridgeError(BridgeErrorKind::Configuration, 'code', '', false);
    }

    public function test_error_preserves_inputs_and_retryability_is_explicit(): void
    {
        $retryable = new BridgeError(BridgeErrorKind::Authentication, ' code ', ' message ', true);
        $notRetryable = new BridgeError(BridgeErrorKind::Authentication, ' code ', ' message ', false);

        self::assertSame(BridgeErrorKind::Authentication, $retryable->kind());
        self::assertSame(' code ', $retryable->code());
        self::assertSame(' message ', $retryable->message());
        self::assertTrue($retryable->isRetryable());
        self::assertFalse($notRetryable->isRetryable());
    }

    public function test_no_throwable_crosses_the_error_boundary(): void
    {
        $propertyTypes = array_map(
            static fn(\ReflectionProperty $property): ?string => $property->getType()?->__toString(),
            (new \ReflectionClass(BridgeError::class))->getProperties(),
        );

        self::assertNotContains(Throwable::class, $propertyTypes);
    }
}
