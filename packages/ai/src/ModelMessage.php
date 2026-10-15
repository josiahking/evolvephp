<?php

declare(strict_types=1);

namespace Evolve\Ai;

use InvalidArgumentException;

final readonly class ModelMessage
{
    /** @var non-empty-list<TextPart|ToolCall|ToolResultPart> */
    public array $parts;

    /** @param array<array-key, mixed> $parts */
    public function __construct(
        public ModelRole $role,
        array $parts,
    ) {
        if ($parts === [] || count($parts) > 128 || !array_is_list($parts)) {
            throw new InvalidArgumentException('A model message needs ordered content.');
        }
        foreach ($parts as $part) {
            if (!$part instanceof TextPart && !$part instanceof ToolCall && !$part instanceof ToolResultPart) {
                throw new InvalidArgumentException('Unsupported message content.');
            }
            if ($role === ModelRole::Tool && !$part instanceof ToolResultPart) {
                throw new InvalidArgumentException('Tool messages need tool results.');
            }
            if ($role !== ModelRole::Tool && $part instanceof ToolResultPart) {
                throw new InvalidArgumentException('Tool results require the tool role.');
            }
            if ($role !== ModelRole::Assistant && $part instanceof ToolCall) {
                throw new InvalidArgumentException('Only assistant messages can request tools.');
            }
        }
        $this->parts = $parts;
    }
}
