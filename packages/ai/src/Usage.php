<?php

declare(strict_types=1);

namespace Evolve\Ai;

use InvalidArgumentException;

final readonly class Usage
{
    public int $totalTokens;

    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
    ) {
        if ($inputTokens < 0 || $outputTokens < 0 || $inputTokens > PHP_INT_MAX - $outputTokens) {
            throw new InvalidArgumentException('Invalid usage measurements.');
        }
        $this->totalTokens = $inputTokens + $outputTokens;
    }
}
