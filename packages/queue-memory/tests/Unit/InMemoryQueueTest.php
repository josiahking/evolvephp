<?php

declare(strict_types=1);

namespace Evolve\Queue\Memory\Tests\Unit;

use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Queue\Contracts\Delivery;
use Evolve\Queue\Contracts\MessageEnvelope;
use Evolve\Queue\Contracts\QueueName;
use Evolve\Queue\Contracts\QueuePublisher;
use Evolve\Queue\Contracts\QueueReceiver;
use Evolve\Queue\Memory\InMemoryQueue;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class InMemoryQueueTest extends TestCase
{
    public function test_publish_then_receive_returns_the_exact_untouched_envelope(): void
    {
        $queue = new InMemoryQueue();
        $name = new QueueName('orders');
        $message = new MessageEnvelope("\0order\xff", [' customer-id ' => '42', 'empty' => '']);

        $queue->publish($name, $message);
        $delivery = $queue->receive($name);

        self::assertInstanceOf(Delivery::class, $delivery);
        $this->assertSameValue($message, $delivery->message());
        self::assertSame("\0order\xff", $delivery->message()->payload());
        self::assertSame([' customer-id ' => '42', 'empty' => ''], $delivery->message()->metadata());
    }

    public function test_empty_or_missing_queue_returns_null(): void
    {
        $queue = new InMemoryQueue();

        self::assertNull($queue->receive(new QueueName('missing')));

        $queue->publish(new QueueName('empty-after-read'), $this->message('only'));
        self::assertNotNull($queue->receive(new QueueName('empty-after-read')));
        self::assertNull($queue->receive(new QueueName('empty-after-read')));
    }

    public function test_messages_are_received_in_fifo_order_per_queue(): void
    {
        $queue = new InMemoryQueue();
        $name = new QueueName('fifo');
        $queue->publish($name, $this->message('first'));
        $queue->publish($name, $this->message('second'));
        $queue->publish($name, $this->message('third'));

        self::assertSame('first', $queue->receive($name)->message()->payload());
        self::assertSame('second', $queue->receive($name)->message()->payload());
        self::assertSame('third', $queue->receive($name)->message()->payload());
        self::assertNull($queue->receive($name));
    }

    public function test_distinct_queue_names_have_independent_fifo_sequences(): void
    {
        $queue = new InMemoryQueue();
        $left = new QueueName('left');
        $right = new QueueName('right');
        $queue->publish($left, $this->message('left-1'));
        $queue->publish($right, $this->message('right-1'));
        $queue->publish($left, $this->message('left-2'));
        $queue->publish($right, $this->message('right-2'));

        self::assertSame('right-1', $queue->receive($right)->message()->payload());
        self::assertSame('left-1', $queue->receive($left)->message()->payload());
        self::assertSame('right-2', $queue->receive($right)->message()->payload());
        self::assertSame('left-2', $queue->receive($left)->message()->payload());
    }

    public function test_numeric_looking_queue_names_remain_distinct(): void
    {
        $queue = new InMemoryQueue();
        $integerLike = new QueueName('1');
        $zeroPadded = new QueueName('01');
        $queue->publish($integerLike, $this->message('integer-like'));
        $queue->publish($zeroPadded, $this->message('zero-padded'));

        self::assertSame('zero-padded', $queue->receive($zeroPadded)?->message()->payload());
        self::assertSame('integer-like', $queue->receive($integerLike)?->message()->payload());
    }

    public function test_separate_adapter_instances_do_not_share_messages(): void
    {
        $first = new InMemoryQueue();
        $second = new InMemoryQueue();
        $name = new QueueName('isolated');
        $first->publish($name, $this->message('private-to-first'));

        self::assertNull($second->receive($name));
        self::assertSame('private-to-first', $first->receive($name)?->message()->payload());
    }

    public function test_acknowledgement_is_terminal_and_repeated_settlement_is_harmless(): void
    {
        $queue = new InMemoryQueue();
        $name = new QueueName('acknowledged');
        $queue->publish($name, $this->message('settled'));
        $delivery = $queue->receive($name);

        self::assertNotNull($delivery);
        $delivery->acknowledge();
        $delivery->acknowledge();
        $delivery->reject();

        self::assertNull($queue->receive($name));
    }

    public function test_rejection_discards_terminally_without_requeue_or_affecting_another_queue(): void
    {
        $queue = new InMemoryQueue();
        $rejected = new QueueName('rejected');
        $other = new QueueName('other');
        $queue->publish($rejected, $this->message('discarded'));
        $queue->publish($other, $this->message('untouched'));
        $delivery = $queue->receive($rejected);

        self::assertNotNull($delivery);
        $delivery->reject();
        $delivery->reject();
        $delivery->acknowledge();

        self::assertNull($queue->receive($rejected));
        self::assertSame('untouched', $queue->receive($other)?->message()->payload());
    }

    public function test_abandoned_unsettled_delivery_is_not_restored(): void
    {
        $queue = new InMemoryQueue();
        $name = new QueueName('abandoned');
        $queue->publish($name, $this->message('owned-by-delivery'));
        $delivery = $queue->receive($name);

        self::assertNotNull($delivery);
        unset($delivery);

        self::assertNull($queue->receive($name));
    }

    public function test_adapter_and_delivery_add_no_public_convenience_or_reset_api(): void
    {
        $queue = new ReflectionClass(InMemoryQueue::class);
        $delivery = new ReflectionClass(\Evolve\Queue\Memory\Internal\InMemoryDelivery::class);
        $queueMethods = array_map(static fn($method): string => $method->getName(), $queue->getMethods(\ReflectionMethod::IS_PUBLIC));
        $deliveryMethods = array_map(static fn($method): string => $method->getName(), $delivery->getMethods(\ReflectionMethod::IS_PUBLIC));
        $deliveryMethods = array_values(array_diff($deliveryMethods, ['__construct']));
        sort($queueMethods);
        sort($deliveryMethods);

        self::assertTrue($queue->isFinal());
        self::assertTrue($queue->implementsInterface(QueuePublisher::class));
        self::assertTrue($queue->implementsInterface(QueueReceiver::class));
        self::assertFalse($queue->implementsInterface(ResetParticipant::class));
        self::assertSame(['publish', 'receive'], $queueMethods);
        self::assertTrue($delivery->isFinal());
        self::assertTrue($delivery->implementsInterface(Delivery::class));
        self::assertFalse($delivery->implementsInterface(ResetParticipant::class));
        self::assertSame(['acknowledge', 'message', 'reject'], $deliveryMethods);
    }

    private function message(string $payload): MessageEnvelope
    {
        return new MessageEnvelope($payload, ['kind' => 'test']);
    }

    private function assertSameValue(mixed $expected, mixed $actual): void
    {
        self::assertSame($expected, $actual);
    }
}
