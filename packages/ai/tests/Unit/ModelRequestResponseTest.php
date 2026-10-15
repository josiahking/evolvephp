<?php

declare(strict_types=1);

namespace Evolve\Ai\Tests\Unit;

use Evolve\Ai\ModelMessage;
use Evolve\Ai\ModelRequest;
use Evolve\Ai\ModelResponse;
use Evolve\Ai\ModelRole;
use Evolve\Ai\TextPart;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ModelRequestResponseTest extends TestCase
{
    public function test_valid_text_exchange_and_immutable_parts(): void
    {
        $message = new ModelMessage(ModelRole::User, [new TextPart('Hello')]);
        $request = new ModelRequest([$message]);
        $response = new ModelResponse([new TextPart('Hi')]);

        self::assertSame([$message], $request->messages);
        self::assertSame('Hi', $response->parts[0]->text);
        self::assertNull($response->usage);
    }

    public function test_empty_request_and_content_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ModelRequest([]);
    }

    public function test_empty_message_content_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ModelMessage(ModelRole::User, []);
    }

    public function test_blank_text_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TextPart('  ');
    }

    public function test_non_list_messages_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ModelRequest(['first' => new ModelMessage(ModelRole::User, [new TextPart('Hi')])]);
    }
    public function test_empty_response_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ModelResponse([]);
    }

    public function test_negative_usage_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new \Evolve\Ai\Usage(-1, 0);
    }

    public function test_usage_overflow_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new \Evolve\Ai\Usage(PHP_INT_MAX, 1);
    }
    public function test_invalid_utf8_text_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TextPart("\xFF");
    }

    public function test_oversized_text_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TextPart(str_repeat('x', 65537));
    }

    public function test_oversized_conversation_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $message = new ModelMessage(ModelRole::User, [new TextPart('Hi')]);
        new ModelRequest(array_fill(0, 129, $message));
    }
    public function test_oversized_message_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ModelMessage(ModelRole::User, array_fill(0, 129, new TextPart('x')));
    }

    public function test_oversized_response_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ModelResponse(array_fill(0, 129, new TextPart('x')));
    }
}
