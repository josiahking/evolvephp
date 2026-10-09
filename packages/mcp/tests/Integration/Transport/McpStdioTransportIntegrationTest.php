<?php

declare(strict_types=1);

namespace Evolve\Mcp\Tests\Integration\Transport;

use Evolve\Core\Console\CommandInput;
use Evolve\Core\Console\CommandOutput;
use Evolve\Core\Console\CommandRegistry;
use Evolve\Core\Console\CommandResult;
use Evolve\Core\Console\CommandRunner;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;
use Evolve\Mcp\Server\McpPromptDefinition;
use Evolve\Mcp\Server\McpResourceDefinition;
use Evolve\Mcp\Server\McpServerBuilder;
use Evolve\Mcp\Server\McpToolDefinition;
use Evolve\Mcp\Transport\McpStdioCommand;
use Evolve\Mcp\Transport\ScopedMcpCapabilityInvoker;
use Mcp\Schema\PromptArgument;
use PHPUnit\Framework\TestCase;

final class McpStdioTransportIntegrationTest extends TestCase
{
    public function test_real_sdk_stdio_exchange_keeps_stdout_protocol_only_and_dispatches_all_capabilities(): void
    {
        $calls = [];
        $registry = new ServiceRegistry();
        $registry->freeze();
        $invoker = new ScopedMcpCapabilityInvoker(
            new ExecutionOrchestrator($registry),
            static function (ExecutionScope $scope, string $serviceId, array $arguments) use (&$calls): mixed {
                $calls[] = [$serviceId, $arguments];

                return match ($serviceId) {
                    'tool.echo' => 'echo: ' . $arguments['text'],
                    'resource.read' => 'resource body',
                    'prompt.greet' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Hello ' . $arguments['name']]]],
                    default => throw new \LogicException('Unexpected service ID.'),
                };
            },
        );
        $builder = new McpServerBuilder('example', '1.0', $invoker);
        $builder->addTool(new McpToolDefinition('echo', 'tool.echo', ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']]));
        $builder->addResource(new McpResourceDefinition('example://document', 'document', 'resource.read'));
        $builder->addPrompt(new McpPromptDefinition('greet', 'prompt.greet', [new PromptArgument('name', required: true)]));

        [$result, $lines, $diagnostics] = $this->exchange($builder, [
            ['id' => 2, 'method' => 'tools/list'],
            ['id' => 3, 'method' => 'resources/list'],
            ['id' => 4, 'method' => 'prompts/list'],
            ['id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['text' => 'safe']]],
            ['id' => 6, 'method' => 'resources/read', 'params' => ['uri' => 'example://document']],
            ['id' => 7, 'method' => 'prompts/get', 'params' => ['name' => 'greet', 'arguments' => ['name' => 'Ada']]],
        ], $invoker);

        self::assertSame(0, $result->exitCode());
        self::assertSame([], $diagnostics);
        $responses = [];
        foreach ($lines as $line) {
            $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('2.0', $message['jsonrpc']);
            $responses[$message['id']] = $message;
        }
        self::assertSame('2025-06-18', $responses[1]['result']['protocolVersion']);
        self::assertSame('echo', $responses[2]['result']['tools'][0]['name']);
        self::assertSame('example://document', $responses[3]['result']['resources'][0]['uri']);
        self::assertSame('greet', $responses[4]['result']['prompts'][0]['name']);
        self::assertSame('echo: safe', $responses[5]['result']['content'][0]['text']);
        self::assertSame('resource body', $responses[6]['result']['contents'][0]['text']);
        self::assertSame('Hello Ada', $responses[7]['result']['messages'][0]['content']['text']);
        self::assertSame([
            ['tool.echo', ['text' => 'safe']],
            ['resource.read', ['uri' => 'example://document']],
            ['prompt.greet', ['name' => 'Ada']],
        ], $calls);
    }

    public function test_cleanup_quarantine_is_reported_by_command_and_later_capabilities_are_not_invoked(): void
    {
        $calls = 0;
        $registry = new ServiceRegistry();
        $registry->freeze();
        $invoker = new ScopedMcpCapabilityInvoker(
            new ExecutionOrchestrator($registry),
            static function (ExecutionScope $scope) use (&$calls): string {
                ++$calls;
                $scope->registerResetParticipant('broken', new class implements \Evolve\Contracts\Execution\ResetParticipant {
                    public function reset(): void
                    {
                        throw new \RuntimeException('reset failed');
                    }
                });

                return 'should not be reported as reusable';
            },
        );
        $builder = new McpServerBuilder('example', '1.0', $invoker);
        $builder->addTool(new McpToolDefinition('echo', 'tool.echo', ['type' => 'object']));
        [$result, $lines] = $this->exchange($builder, [
            ['id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => []]],
            ['id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => []]],
        ], $invoker);

        self::assertSame(1, $result->exitCode());
        self::assertSame(1, $calls);
        $responses = [];
        foreach ($lines as $line) {
            $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $responses[$message['id']] = $message;
        }
        self::assertArrayHasKey('error', $responses[2]);
        self::assertArrayHasKey('error', $responses[3]);
    }
    public function test_malformed_and_oversized_lines_do_not_break_later_sdk_requests(): void
    {
        $registry = new ServiceRegistry();
        $registry->freeze();
        $invoker = new ScopedMcpCapabilityInvoker(
            new ExecutionOrchestrator($registry),
            static fn(): string => 'unused',
        );
        $builder = new McpServerBuilder('example', '1.0', $invoker);
        $builder->addTool(new McpToolDefinition('echo', 'tool.echo', ['type' => 'object']));
        [$result, $lines] = $this->exchange(
            $builder,
            [['id' => 2, 'method' => 'tools/list']],
            $invoker,
            ['{broken', str_repeat('x', 600)],
            512,
        );

        self::assertSame(0, $result->exitCode());
        $responses = array_map(
            static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            $lines,
        );
        self::assertSame('2.0', $responses[0]['jsonrpc']);
        self::assertArrayHasKey('error', $responses[0]);
        self::assertSame(1, $responses[1]['id']);
        self::assertSame(2, $responses[2]['id']);
        self::assertSame('echo', $responses[2]['result']['tools'][0]['name']);
    }
    public function test_command_rejects_streams_with_incorrect_access_modes(): void
    {
        $registry = new ServiceRegistry();
        $registry->freeze();
        $invoker = new ScopedMcpCapabilityInvoker(new ExecutionOrchestrator($registry), static fn(): null => null);
        $definition = (new McpServerBuilder('example', '1.0', $invoker))->build();
        $writePath = tempnam(sys_get_temp_dir(), 'mcp-write-');
        $readPath = tempnam(sys_get_temp_dir(), 'mcp-read-');
        self::assertIsString($writePath);
        self::assertIsString($readPath);
        $writeOnly = fopen($writePath, 'w');
        $readOnly = fopen($readPath, 'r');
        $duplex = fopen('php://temp', 'w+');
        self::assertIsResource($writeOnly);
        self::assertIsResource($readOnly);
        self::assertIsResource($duplex);

        try {
            foreach ([[$writeOnly, $duplex], [$duplex, $readOnly]] as [$input, $output]) {
                try {
                    new McpStdioCommand($definition, $invoker, $input, $output);
                    self::fail('Expected invalid stream access mode rejection.');
                } catch (\InvalidArgumentException) {
                }
            }
        } finally {
            fclose($writeOnly);
            fclose($readOnly);
            fclose($duplex);
            unlink($writePath);
            unlink($readPath);
        }
    }
    public function test_command_rejects_an_invoker_that_is_not_bound_to_its_server(): void
    {
        $registry = new ServiceRegistry();
        $registry->freeze();
        $bound = new ScopedMcpCapabilityInvoker(new ExecutionOrchestrator($registry), static fn(): null => null);
        $other = new ScopedMcpCapabilityInvoker(new ExecutionOrchestrator($registry), static fn(): null => null);
        $definition = (new McpServerBuilder('example', '1.0', $bound))->build();
        $input = fopen('php://temp', 'w+');
        $output = fopen('php://temp', 'w+');
        self::assertIsResource($input);
        self::assertIsResource($output);

        try {
            $this->expectException(\InvalidArgumentException::class);
            new McpStdioCommand($definition, $other, $input, $output);
        } finally {
            fclose($input);
            fclose($output);
        }
    }
    /** @param list<array<string, mixed>> $requests
     *  @param list<string> $rawPrefix
     *  @return array{CommandResult, list<string>, list<string>}
     */
    private function exchange(McpServerBuilder $builder, array $requests, ScopedMcpCapabilityInvoker $invoker, array $rawPrefix = [], int $maxLineBytes = 4194304): array
    {
        $input = fopen('php://temp', 'w+');
        self::assertIsResource($input);
        $messages = [
            ['id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ]],
            ['method' => 'notifications/initialized'],
            ...$requests,
        ];
        foreach ($rawPrefix as $line) {
            fwrite($input, $line . "\n");
        }
        foreach ($messages as $message) {
            fwrite($input, json_encode(['jsonrpc' => '2.0'] + $message, JSON_THROW_ON_ERROR) . "\n");
        }
        rewind($input);
        $path = tempnam(sys_get_temp_dir(), 'evolve-mcp-');
        self::assertIsString($path);
        $protocolOutput = fopen($path, 'w');
        self::assertIsResource($protocolOutput);
        $diagnostics = new class implements CommandOutput {
            /** @var list<string> */
            public array $errors = [];
            public function write(string $message): void
            {
                throw new \LogicException('STDOUT must be protocol only.');
            }
            public function writeError(string $message): void
            {
                $this->errors[] = $message;
            }
        };
        try {
            $command = new McpStdioCommand($builder->build(), $invoker, $input, $protocolOutput, $maxLineBytes);
            $outer = new ServiceRegistry();
            $outer->freeze();
            $outcome = (new CommandRunner(new CommandRegistry([$command]), new ExecutionOrchestrator($outer)))
                ->run($command->name(), new CommandInput(), $diagnostics);
            self::assertTrue($outcome->primarySucceeded());
            $result = $outcome->primaryResult();
            self::assertInstanceOf(CommandResult::class, $result);
            $content = file_get_contents($path);
            self::assertIsString($content);

            return [$result, array_values(array_filter(explode("\n", trim($content)))), $diagnostics->errors];
        } finally {
            unlink($path);
        }
    }
}
