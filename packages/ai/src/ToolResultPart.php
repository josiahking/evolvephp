<?php

declare(strict_types=1);

namespace Evolve\Ai;

use InvalidArgumentException;

final readonly class ToolResultPart
{
    public function __construct(
        public string $toolCallId,
        public string $content,
        public bool $isError = false,
    ) {
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $toolCallId)) {
            throw new InvalidArgumentException('Invalid tool result identity.');
        }
        if (preg_match('//u', $content) !== 1) {
            throw new InvalidArgumentException('Invalid tool result content.');
        }
        if (strlen($content) > 65536) {
            throw new InvalidArgumentException('Tool result exceeds the size limit.');
        }
    }
}
