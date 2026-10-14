<?php

declare(strict_types=1);

namespace Evolve\Ai;

use InvalidArgumentException;

final readonly class ModelRequest
{
    /** @var non-empty-list<ModelMessage> */
    public array $messages;

    /** @var list<ToolDefinition> */
    public array $tools;

    /**
     * @param array<array-key, mixed> $messages
     * @param array<array-key, mixed> $tools
     */
    public function __construct(
        array $messages,
        array $tools = [],
    ) {
        if ($messages === [] || count($messages) > 128 || !array_is_list($messages) || count($tools) > 64 || !array_is_list($tools)) {
            throw new InvalidArgumentException('Invalid model request.');
        }
        $toolNames = [];
        foreach ($tools as $tool) {
            if (!$tool instanceof ToolDefinition || isset($toolNames[$tool->name])) {
                throw new InvalidArgumentException('Invalid or duplicate tool definition.');
            }
            $toolNames[$tool->name] = true;
        }
        $pending = [];
        $completed = [];
        foreach ($messages as $message) {
            if (!$message instanceof ModelMessage) {
                throw new InvalidArgumentException('Invalid model message.');
            }
            foreach ($message->parts as $part) {
                if ($part instanceof ToolCall) {
                    if (isset($pending[$part->id]) || isset($completed[$part->id])) {
                        throw new InvalidArgumentException('Duplicate tool call identity.');
                    }
                    $pending[$part->id] = true;
                }
                if ($part instanceof ToolResultPart) {
                    if (!isset($pending[$part->toolCallId])) {
                        throw new InvalidArgumentException('Unmatched or duplicate tool result.');
                    }
                    unset($pending[$part->toolCallId]);
                    $completed[$part->toolCallId] = true;
                }
            }
        }
        $this->messages = $messages;
        $this->tools = $tools;
    }
}
