<?php

declare(strict_types=1);

namespace Evolve\Ai\Tests\Unit;

use Evolve\Ai\ModelMessage;
use Evolve\Ai\ModelRequest;
use Evolve\Ai\ModelResponse;
use Evolve\Ai\ModelRole;
use Evolve\Ai\TextPart;
use Evolve\Ai\ToolCall;
use Evolve\Ai\ToolDefinition;
use Evolve\Ai\ToolResultPart;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ToolHandoffTest extends TestCase
{
    public function test_explicit_tool_handoff_preserves_call_identity(): void
    {
        $call = new ToolCall('call_1', 'weather', ['city' => 'Lagos']);
        $response = new ModelResponse([$call]);
        $followup = new ModelRequest([
            new ModelMessage(ModelRole::User, [new TextPart('Weather?')]),
            new ModelMessage(ModelRole::Assistant, $response->parts),
            new ModelMessage(ModelRole::Tool, [new ToolResultPart('call_1', 'Sunny')]),
        ], [new ToolDefinition('weather', 'Get weather', ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]])]);

        self::assertSame('call_1', $followup->messages[2]->parts[0]->toolCallId);
    }

    public function test_unmatched_tool_result_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ModelRequest([new ModelMessage(ModelRole::Tool, [new ToolResultPart('unknown', 'No')])]);
    }

    public function test_duplicate_tool_calls_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ModelResponse([new ToolCall('same', 'weather', []), new ToolCall('same', 'weather', [])]);
    }

    public function test_duplicate_tool_definitions_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $tool = new ToolDefinition('weather', 'Get weather', ['type' => 'object']);
        new ModelRequest([new ModelMessage(ModelRole::User, [new TextPart('Hi')])], [$tool, $tool]);
    }

    public function test_invalid_schema_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ToolDefinition('weather', 'Get weather', ['type' => 'array']);
    }

    public function test_tool_result_in_user_message_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ModelMessage(ModelRole::User, [new ToolResultPart('call_1', 'No')]);
    }
    public function test_malformed_object_schema_properties_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ToolDefinition('weather', 'Get weather', ['type' => 'object', 'properties' => ['bad']]);
    }

    public function test_duplicate_tool_result_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ModelRequest([
            new ModelMessage(ModelRole::Assistant, [new ToolCall('call_1', 'weather', [])]),
            new ModelMessage(ModelRole::Tool, [new ToolResultPart('call_1', 'First')]),
            new ModelMessage(ModelRole::Tool, [new ToolResultPart('call_1', 'Second')]),
        ]);
    }

    public function test_malformed_call_identity_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ToolCall('bad id', 'weather', []);
    }

    public function test_nonfinite_tool_argument_is_rejected_without_leaking_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ToolCall('call_1', 'weather', ['value' => INF]);
    }
    public function test_too_many_tool_definitions_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $tools = [];
        for ($i = 0; $i < 65; ++$i) {
            $tools[] = new ToolDefinition('tool_' . $i, 'Tool', ['type' => 'object']);
        }
        new ModelRequest([new ModelMessage(ModelRole::User, [new TextPart('Hi')])], $tools);
    }
    public function test_tool_result_rejects_invalid_utf8(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ToolResultPart('call_1', "\xFF");
    }

    public function test_tool_description_rejects_invalid_utf8_without_echoing_it(): void
    {
        try {
            new ToolDefinition('weather', "secret\xFF", ['type' => 'object']);
            self::fail('Expected invalid UTF-8 to be rejected.');
        } catch (InvalidArgumentException $error) {
            self::assertStringNotContainsString('secret', $error->getMessage());
        }
    }

    public function test_empty_arguments_and_properties_serialize_as_objects(): void
    {
        $call = new ToolCall('call_1', 'weather', []);
        $definition = new ToolDefinition('weather', 'Get weather', [
            'type' => 'object',
            'properties' => ['nested' => ['type' => 'object', 'properties' => []]],
        ]);
        $emptyDefinition = new ToolDefinition('empty', 'No input', ['type' => 'object', 'properties' => []]);

        self::assertSame('{}', $call->argumentsJson);
        self::assertSame('{"type":"object","properties":{}}', $emptyDefinition->inputSchemaJson);
        $decoded = json_decode($definition->inputSchemaJson, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $decoded->properties);
        self::assertInstanceOf(\stdClass::class, $decoded->properties->nested->properties);
    }

    public function test_tool_call_copies_nested_references_before_the_caller_mutates_them(): void
    {
        $nested = ['value' => 'safe'];
        $arguments = ['nested' => &$nested];
        $call = new ToolCall('call_1', 'weather', $arguments);
        $nested['value'] = str_repeat('x', 70000);

        self::assertTrue($call->arguments['nested']['value'] === 'safe');
        self::assertSame('{"nested":{"value":"safe"}}', $call->argumentsJson);
    }

    public function test_tool_definition_copies_nested_references_before_the_caller_mutates_them(): void
    {
        $property = ['type' => 'string'];
        $schema = ['type' => 'object', 'properties' => ['city' => &$property]];
        $definition = new ToolDefinition('weather', 'Get weather', $schema);
        $property['type'] = str_repeat('x', 70000);

        self::assertTrue($definition->inputSchema['properties']['city']['type'] === 'string');
        self::assertSame('{"type":"object","properties":{"city":{"type":"string"}}}', $definition->inputSchemaJson);
    }

    public function test_mutable_objects_are_rejected_from_tool_payloads(): void
    {
        $object = new \stdClass();
        $object->value = 'safe';
        $this->expectException(InvalidArgumentException::class);
        new ToolCall('call_1', 'weather', ['nested' => $object]);
    }

    public function test_mutable_objects_are_rejected_from_tool_schemas(): void
    {
        $object = new \stdClass();
        $object->type = 'string';
        $this->expectException(InvalidArgumentException::class);
        new ToolDefinition('weather', 'Get weather', ['type' => 'object', 'properties' => ['city' => $object]]);
    }

    public function test_oversized_nested_tool_arguments_are_rejected_without_leaking_them(): void
    {
        try {
            new ToolCall('call_1', 'weather', ['nested' => ['value' => str_repeat('secret', 12000)]]);
            self::fail('Expected oversized arguments to be rejected.');
        } catch (InvalidArgumentException $error) {
            self::assertStringNotContainsString('secret', $error->getMessage());
        }
    }

    public function test_oversized_nested_tool_schema_is_rejected_without_leaking_it(): void
    {
        try {
            new ToolDefinition('weather', 'Get weather', ['type' => 'object', 'properties' => [
                'city' => ['description' => str_repeat('secret', 12000)],
            ]]);
            self::fail('Expected oversized schema to be rejected.');
        } catch (InvalidArgumentException $error) {
            self::assertStringNotContainsString('secret', $error->getMessage());
        }
    }
    public function test_tool_call_rejects_throwing_json_callback_without_running_it(): void
    {
        $callbackCount = 0;
        $value = new class ($callbackCount) implements \JsonSerializable {
            public function __construct(private int &$callbackCount) {}

            public function jsonSerialize(): mixed
            {
                ++$this->callbackCount;
                throw new \RuntimeException('sensitive tool argument');
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid tool arguments.');
        new ToolCall('call_1', 'weather', ['nested' => $value]);
    }

    public function test_tool_call_rejects_json_callback_without_side_effects(): void
    {
        $callbackCount = 0;
        $value = new class ($callbackCount) implements \JsonSerializable {
            public function __construct(private int &$callbackCount) {}

            public function jsonSerialize(): mixed
            {
                ++$this->callbackCount;

                return ['value' => 'safe'];
            }
        };

        try {
            new ToolCall('call_1', 'weather', ['nested' => $value]);
            self::fail('Expected object payload rejection.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Invalid tool arguments.', $error->getMessage());
        }
        self::assertSame(0, $callbackCount);
    }

    public function test_tool_definition_rejects_throwing_json_callback_without_running_it(): void
    {
        $callbackCount = 0;
        $value = new class ($callbackCount) implements \JsonSerializable {
            public function __construct(private int &$callbackCount) {}

            public function jsonSerialize(): mixed
            {
                ++$this->callbackCount;
                throw new \RuntimeException('sensitive tool schema');
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid tool input schema.');
        new ToolDefinition('weather', 'Get weather', ['type' => 'object', 'metadata' => ['nested' => $value]]);
    }

    public function test_tool_definition_rejects_json_callback_without_side_effects(): void
    {
        $callbackCount = 0;
        $value = new class ($callbackCount) implements \JsonSerializable {
            public function __construct(private int &$callbackCount) {}

            public function jsonSerialize(): mixed
            {
                ++$this->callbackCount;

                return ['value' => 'safe'];
            }
        };

        try {
            new ToolDefinition('weather', 'Get weather', ['type' => 'object', 'metadata' => ['nested' => $value]]);
            self::fail('Expected object schema rejection.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('Invalid tool input schema.', $error->getMessage());
        }
        self::assertSame(0, $callbackCount);
    }
}
