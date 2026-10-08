<?php

declare(strict_types=1);

namespace Evolve\Mcp\Tests\Unit\Server;

use Evolve\Mcp\Server\McpCapabilityInvoker;
use Evolve\Mcp\Server\McpPromptDefinition;
use Evolve\Mcp\Server\McpResourceDefinition;
use Evolve\Mcp\Server\McpServerBuilder;
use Evolve\Mcp\Server\McpToolDefinition;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\PromptArgument;
use Mcp\Server\Transport\InMemoryTransport;
use PHPUnit\Framework\TestCase;

final class McpServerInvocationTest extends TestCase
{
    public function test_sdk_lists_and_dispatches_explicit_capabilities_without_eager_invocation(): void
    {
        $invoker = new class implements McpCapabilityInvoker {
            /** @var list<array{string, array<string, mixed>}> */
            private array $calls = [];

            public function invoke(string $serviceId, array $arguments): mixed
            {
                $this->calls[] = [$serviceId, $arguments];

                return match ($serviceId) {
                    'tool.echo' => 'echo: ' . $arguments['text'],
                    'resource.read' => 'resource body',
                    'prompt.greet' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Hello ' . $arguments['name']]]],
                    default => throw new \LogicException("Unexpected service ID: {$serviceId}"),
                };
            }

            /** @return list<array{string, array<string, mixed>}> */
            public function calls(): array
            {
                return $this->calls;
            }
        };
        $builder = new McpServerBuilder('example', '1.0', $invoker);
        $builder->addTool(new McpToolDefinition('echo', 'tool.echo', [
            'type' => 'object',
            'properties' => ['text' => ['type' => 'string']],
            'required' => ['text'],
        ]));
        $builder->addResource(new McpResourceDefinition('example://document', 'document', 'resource.read'));
        $builder->addPrompt(new McpPromptDefinition('greet', 'prompt.greet', [new PromptArgument('name', required: true)]));
        $server = $builder->build()->server();
        self::assertSame([], $invoker->calls());

        $responses = self::exchange($server, [
            ['id' => 2, 'method' => 'tools/list'],
            ['id' => 3, 'method' => 'resources/list'],
            ['id' => 4, 'method' => 'prompts/list'],
            ['id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['text' => 'safe']]],
            ['id' => 6, 'method' => 'resources/read', 'params' => ['uri' => 'example://document']],
            ['id' => 7, 'method' => 'prompts/get', 'params' => ['name' => 'greet', 'arguments' => ['name' => 'Ada']]],
            ['id' => 8, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['text' => 'fresh']]],
        ]);

        self::assertArrayHasKey(2, $responses, json_encode($responses));
        self::assertSame('echo', $responses[2]['result']['tools'][0]['name']);
        self::assertSame('example://document', $responses[3]['result']['resources'][0]['uri']);
        self::assertSame('greet', $responses[4]['result']['prompts'][0]['name']);
        self::assertSame('name', $responses[4]['result']['prompts'][0]['arguments'][0]['name']);
        self::assertSame('echo: safe', $responses[5]['result']['content'][0]['text']);
        self::assertSame('resource body', $responses[6]['result']['contents'][0]['text']);
        self::assertSame('Hello Ada', $responses[7]['result']['messages'][0]['content']['text']);
        self::assertSame('echo: fresh', $responses[8]['result']['content'][0]['text']);
        self::assertSame([
            ['tool.echo', ['text' => 'safe']],
            ['resource.read', ['uri' => 'example://document']],
            ['prompt.greet', ['name' => 'Ada']],
            ['tool.echo', ['text' => 'fresh']],
        ], $invoker->calls());
    }

    public function test_invalid_inputs_and_handler_failure_keep_sdk_error_semantics_and_do_not_disclose_secrets(): void
    {
        $invoker = new class implements McpCapabilityInvoker {
            /** @var list<array{string, array<string, mixed>}> */
            private array $calls = [];

            public function invoke(string $serviceId, array $arguments): mixed
            {
                $this->calls[] = [$serviceId, $arguments];

                throw new \RuntimeException('credential=private-secret');
            }

            /** @return list<array{string, array<string, mixed>}> */
            public function calls(): array
            {
                return $this->calls;
            }
        };
        $builder = new McpServerBuilder('example', '1.0', $invoker);
        $builder->addTool(new McpToolDefinition('fail', 'tool.fail', [
            'type' => 'object',
            'properties' => ['value' => ['type' => 'integer']],
            'required' => ['value'],
        ]));
        $builder->addResource(new McpResourceDefinition('example://failure', 'failure', 'resource.fail'));
        $builder->addPrompt(new McpPromptDefinition('failure', 'prompt.fail'));
        $responses = self::exchange($builder->build()->server(), [
            ['id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'missing', 'arguments' => []]],
            ['id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'fail', 'arguments' => ['value' => 'wrong']]],
            ['id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'fail', 'arguments' => ['value' => 1]]],
            ['id' => 5, 'method' => 'resources/read', 'params' => ['uri' => 'example://missing']],
            ['id' => 6, 'method' => 'prompts/get', 'params' => ['name' => 'missing']],
            ['id' => 7, 'method' => 'resources/read', 'params' => ['uri' => 'example://failure']],
            ['id' => 8, 'method' => 'prompts/get', 'params' => ['name' => 'failure']],
        ]);

        self::assertSame('2025-06-18', $responses[1]['result']['protocolVersion']);

        self::assertProtocolError($responses[2], 2, Error::INVALID_PARAMS);
        self::assertStringContainsString('missing', $responses[2]['error']['message']);
        self::assertArrayNotHasKey('data', $responses[2]['error']);

        self::assertProtocolError($responses[3], 3, Error::INVALID_PARAMS);
        self::assertStringContainsString("Invalid parameters for tool 'fail'", $responses[3]['error']['message']);
        self::assertNotEmpty($responses[3]['error']['data']['validation_errors']);

        self::assertSame([
            'jsonrpc' => '2.0',
            'id' => 4,
            'error' => ['code' => Error::INTERNAL_ERROR, 'message' => 'Error while executing tool'],
        ], $responses[4]);

        // This handshake version predates SEP-2164, so an unknown resource uses -32002.
        self::assertProtocolError($responses[5], 5, Error::RESOURCE_NOT_FOUND);
        self::assertStringContainsString('example://missing', $responses[5]['error']['message']);
        self::assertArrayNotHasKey('data', $responses[5]['error']);

        self::assertProtocolError($responses[6], 6, Error::INVALID_PARAMS);
        self::assertStringContainsString('missing', $responses[6]['error']['message']);
        self::assertArrayNotHasKey('data', $responses[6]['error']);

        self::assertSame([
            'jsonrpc' => '2.0',
            'id' => 7,
            'error' => ['code' => Error::INTERNAL_ERROR, 'message' => 'Error while reading resource'],
        ], $responses[7]);
        self::assertSame([
            'jsonrpc' => '2.0',
            'id' => 8,
            'error' => ['code' => Error::INTERNAL_ERROR, 'message' => 'Error while handling prompt'],
        ], $responses[8]);

        self::assertSame([
            ['tool.fail', ['value' => 1]],
            ['resource.fail', ['uri' => 'example://failure']],
            ['prompt.fail', []],
        ], $invoker->calls());
        self::assertStringNotContainsString('private-secret', json_encode($responses, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $response */
    private static function assertProtocolError(array $response, int $id, int $code): void
    {
        self::assertSame('2.0', $response['jsonrpc']);
        self::assertSame($id, $response['id']);
        self::assertArrayNotHasKey('result', $response);
        self::assertSame($code, $response['error']['code']);
        self::assertIsString($response['error']['message']);
        self::assertNotSame('', $response['error']['message']);
    }

    /** @param list<array<string, mixed>> $requests
     *  @return array<int, array<string, mixed>>
     */
    private static function exchange(\Mcp\Server $server, array $requests): array
    {
        $messages = [
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ]], JSON_THROW_ON_ERROR),
            json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], JSON_THROW_ON_ERROR),
        ];
        foreach ($requests as $request) {
            $messages[] = json_encode(['jsonrpc' => '2.0'] + $request, JSON_THROW_ON_ERROR);
        }

        $transport = new class ($messages) extends InMemoryTransport {
            /** @param list<string> $inputs */
            public function __construct(private array $inputs)
            {
                parent::__construct();
            }
            public function listen(): mixed
            {
                foreach ($this->inputs as $message) {
                    $this->handleMessage($message, $this->sessionId);
                    if ($this->sessionId !== null) {
                        foreach ($this->getOutgoingMessages($this->sessionId) as $outgoing) {
                            $this->send($outgoing['message'], $outgoing['context']);
                        }
                    }
                }
                return null;
            }
            /** @var array<int, array<string, mixed>> */
            public array $responses = [];
            public function send(string $data, array $context): void
            {
                parent::send($data, $context);
                $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
                if (isset($decoded['id'])) {
                    $this->responses[$decoded['id']] = $decoded;
                }
            }
        };
        $server->run($transport);

        return $transport->responses;
    }
}
