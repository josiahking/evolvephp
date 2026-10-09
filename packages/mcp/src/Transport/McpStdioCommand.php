<?php

declare(strict_types=1);

namespace Evolve\Mcp\Transport;

use Evolve\Core\Console\Command;
use Evolve\Core\Console\CommandInput;
use Evolve\Core\Console\CommandOutput;
use Evolve\Core\Console\CommandResult;
use Evolve\Mcp\Server\McpServerDefinition;
use InvalidArgumentException;
use Mcp\Server\Transport\StdioTransport;
use Throwable;

/**
 * Runs the SDK's handshake-era STDIO server on host-supplied streams.
 *
 * The SDK owns and closes both protocol streams when its run ends.
 */
final readonly class McpStdioCommand implements Command
{
    /**
     * @param resource $input
     * @param resource $protocolOutput
     */
    public function __construct(
        private McpServerDefinition $definition,
        private ScopedMcpCapabilityInvoker $invoker,
        private mixed $input,
        private mixed $protocolOutput,
        private int $maxLineBytes = StdioTransport::DEFAULT_MAX_LINE_BYTES,
    ) {
        if ($definition->invoker() !== $invoker) {
            throw new InvalidArgumentException('MCP STDIO command invoker does not match the server definition.');
        }
        if (!is_resource($input) || get_resource_type($input) !== 'stream') {
            throw new InvalidArgumentException('MCP input must be a readable stream.');
        }
        if (!is_resource($protocolOutput) || get_resource_type($protocolOutput) !== 'stream') {
            throw new InvalidArgumentException('MCP protocol output must be a writable stream.');
        }
        $inputMode = stream_get_meta_data($input)['mode'];
        if (!str_contains($inputMode, 'r') && !str_contains($inputMode, '+')) {
            throw new InvalidArgumentException('MCP input must be readable.');
        }
        $outputMode = stream_get_meta_data($protocolOutput)['mode'];
        if (strpbrk($outputMode, 'waxc+') === false) {
            throw new InvalidArgumentException('MCP protocol output must be writable.');
        }
        if ($maxLineBytes < 1) {
            throw new InvalidArgumentException('MCP maximum line size must be positive.');
        }
    }

    public function name(): string
    {
        return 'mcp:serve';
    }

    public function description(): string
    {
        return 'Serve MCP over STDIO.';
    }

    public function execute(CommandInput $input, CommandOutput $output): CommandResult
    {
        try {
            $status = $this->definition->server()->run(new StdioTransport(
                $this->input,
                $this->protocolOutput,
                maxLineBytes: $this->maxLineBytes,
            ));

            if ($this->invoker->quarantineFailure() !== null) {
                $output->writeError('MCP capability execution is unsafe; process reuse is not allowed.');

                return new CommandResult(1);
            }

            return new CommandResult($status);
        } catch (Throwable) {
            $output->writeError('MCP STDIO server failed.');

            return new CommandResult(1);
        }
    }
}
