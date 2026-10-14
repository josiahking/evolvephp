<?php

declare(strict_types=1);

namespace Evolve\Ai;

use InvalidArgumentException;

final readonly class ModelResponse
{
    /** @var non-empty-list<TextPart|ToolCall> */
    public array $parts;

    /** @param array<array-key, mixed> $parts */
    public function __construct(
        array $parts,
        public ?Usage $usage = null,
    ) {
        if ($parts === [] || count($parts) > 128 || !array_is_list($parts)) {
            throw new InvalidArgumentException('A model response needs ordered content.');
        }
        $ids = [];
        foreach ($parts as $part) {
            if (!$part instanceof TextPart && !$part instanceof ToolCall) {
                throw new InvalidArgumentException('Unsupported model response content.');
            }
            if ($part instanceof ToolCall) {
                if (isset($ids[$part->id])) {
                    throw new InvalidArgumentException('Duplicate tool call identity.');
                }
                $ids[$part->id] = true;
            }
        }
        $this->parts = $parts;
    }
}
