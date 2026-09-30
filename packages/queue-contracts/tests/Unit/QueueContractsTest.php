<?php

declare(strict_types=1);

namespace Evolve\Queue\Contracts\Tests\Unit;

use Evolve\Contracts\Exception\EvolveException;
use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Queue\Contracts\Delivery;
use Evolve\Queue\Contracts\Exception\QueueException;
use Evolve\Queue\Contracts\MessageEnvelope;
use Evolve\Queue\Contracts\QueueFailureCategory;
use Evolve\Queue\Contracts\QueueName;
use Evolve\Queue\Contracts\QueueOperation;
use Evolve\Queue\Contracts\QueuePublisher;
use Evolve\Queue\Contracts\QueueReceiver;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class QueueContractsTest extends TestCase
{
    public function test_publisher_has_exact_publish_signature(): void
    {
        $method = (new ReflectionClass(QueuePublisher::class))->getMethod('publish');

        self::assertSame('void', (string) $method->getReturnType());
        self::assertSame([QueueName::class, MessageEnvelope::class], array_map(
            static fn($parameter): string => (string) $parameter->getType(),
            $method->getParameters(),
        ));
    }

    public function test_operations_explicitly_declare_queue_exception_failures(): void
    {
        foreach ([
            [QueuePublisher::class, 'publish'],
            [QueueReceiver::class, 'receive'],
            [Delivery::class, 'acknowledge'],
            [Delivery::class, 'reject'],
        ] as [$contract, $methodName]) {
            $this->assertQueueExceptionIsDeclared((new ReflectionClass($contract))->getMethod($methodName));
        }
    }

    public function test_receiver_has_one_nullable_nonblocking_delivery_result(): void
    {
        $method = (new ReflectionClass(QueueReceiver::class))->getMethod('receive');

        self::assertSame('?' . Delivery::class, (string) $method->getReturnType());
        self::assertSame([QueueName::class], array_map(
            static fn($parameter): string => (string) $parameter->getType(),
            $method->getParameters(),
        ));
    }

    public function test_delivery_is_transient_settlement_capability_without_reset_contract(): void
    {
        $delivery = new ReflectionClass(Delivery::class);
        $methodNames = array_map(static fn($method): string => $method->getName(), $delivery->getMethods());
        sort($methodNames);

        self::assertTrue($delivery->isInterface());
        self::assertFalse($delivery->implementsInterface(ResetParticipant::class));
        self::assertSame(['acknowledge', 'message', 'reject'], $methodNames);
        self::assertSame(MessageEnvelope::class, (string) $delivery->getMethod('message')->getReturnType());
        self::assertSame('void', (string) $delivery->getMethod('acknowledge')->getReturnType());
        self::assertSame('void', (string) $delivery->getMethod('reject')->getReturnType());
    }

    public function test_queue_exception_has_narrow_evolve_exception_boundary_and_accessors(): void
    {
        $exception = new ReflectionClass(QueueException::class);
        $methodNames = array_map(
            static fn($method): string => $method->getName(),
            array_filter(
                $exception->getMethods(),
                static fn($method): bool => !in_array($method->getName(), [
                    '__toString', 'getCode', 'getFile', 'getLine', 'getMessage', 'getPrevious',
                    'getTrace', 'getTraceAsString',
                ], true),
            ),
        );
        sort($methodNames);

        self::assertTrue($exception->isInterface());
        self::assertTrue($exception->implementsInterface(EvolveException::class));
        self::assertContains(EvolveException::class, $exception->getInterfaceNames());
        self::assertSame(['category', 'operation'], $methodNames);
        self::assertSame(QueueOperation::class, (string) $exception->getMethod('operation')->getReturnType());
        self::assertSame(QueueFailureCategory::class, (string) $exception->getMethod('category')->getReturnType());
    }

    public function test_queue_operation_cases_are_frozen(): void
    {
        self::assertSame([
            'Publish' => 'publish',
            'Receive' => 'receive',
            'Acknowledge' => 'acknowledge',
            'Reject' => 'reject',
        ], $this->backedValues(QueueOperation::class));
    }

    public function test_queue_failure_categories_are_frozen(): void
    {
        self::assertSame([
            'Authentication' => 'authentication',
            'Authorization' => 'authorization',
            'Timeout' => 'timeout',
            'Unavailable' => 'unavailable',
            'Capacity' => 'capacity',
            'Payload' => 'payload',
            'Settlement' => 'settlement',
            'Transport' => 'transport',
            'Unknown' => 'unknown',
        ], $this->backedValues(QueueFailureCategory::class));
    }

    /** @return array<string, string> */
    private function backedValues(string $enum): array
    {
        $values = [];

        foreach ($enum::cases() as $case) {
            $values[$case->name] = $case->value;
        }

        return $values;
    }

    private function assertQueueExceptionIsDeclared(ReflectionMethod $method): void
    {
        $docComment = $method->getDocComment();

        self::assertIsString($docComment);
        self::assertMatchesRegularExpression(
            '/@throws\s+QueueException\b/',
            $docComment,
            $method->getDeclaringClass()->getName() . '::' . $method->getName() . ' must declare QueueException.',
        );
    }
}
