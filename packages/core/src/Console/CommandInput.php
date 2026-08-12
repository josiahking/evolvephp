<?php

declare(strict_types=1);

namespace Evolve\Core\Console;

use InvalidArgumentException;

final readonly class CommandInput
{
    /**
     * @var list<string>
     */
    private array $tokens;

    /**
     * @param array<array-key, mixed> $tokens
     */
    public function __construct(array $tokens = [])
    {
        if (! array_is_list($tokens)) {
            throw new InvalidArgumentException('Command input tokens must be a list.');
        }

        foreach ($tokens as $token) {
            if (! is_string($token)) {
                throw new InvalidArgumentException('Command input tokens must contain only strings.');
            }
        }

        $this->tokens = $tokens;
    }

    /**
     * @return list<string>
     */
    public function tokens(): array
    {
        return $this->tokens;
    }
}
