<?php

declare(strict_types=1);

namespace Evolve\Insight\Infrastructure;

use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionContextAttacher;
use Evolve\Core\Execution\ExecutionContextAttachment;

final class ExecutionCorrelation implements ExecutionContextAttacher
{
    private const MAXIMUM_REPEAT_GROUPS = 64;

    /** @var list<array{identifier: string, repeats: array<string, int>}> */
    private array $frames = [];

    /** @phpstan-impure */
    public function attach(ExecutionContext $context): ExecutionContextAttachment
    {
        $this->frames[] = ['identifier' => $context->identifier()->value(), 'repeats' => []];

        return new class ($this) implements ExecutionContextAttachment {
            private bool $detached = false;

            public function __construct(private ExecutionCorrelation $correlation) {}

            public function detach(): void
            {
                if ($this->detached) {
                    return;
                }

                $this->detached = true;
                $this->correlation->detach();
            }
        };
    }

    public function identifier(): ?string
    {
        $index = array_key_last($this->frames);

        if ($index === null) {
            return null;
        }

        return $this->frames[$index]['identifier'];
    }

    public function repeat(string $fingerprint): ?int
    {
        if ($fingerprint === '' || strlen($fingerprint) > 64) {
            return null;
        }

        $index = array_key_last($this->frames);

        if ($index === null) {
            return null;
        }

        if (!isset($this->frames[$index]['repeats'][$fingerprint]) && count($this->frames[$index]['repeats']) >= self::MAXIMUM_REPEAT_GROUPS) {
            return null;
        }

        $count = min(($this->frames[$index]['repeats'][$fingerprint] ?? 0) + 1, 65535);
        $this->frames[$index]['repeats'][$fingerprint] = $count;

        return $count;
    }

    public function detach(): void
    {
        array_pop($this->frames);
    }
}
