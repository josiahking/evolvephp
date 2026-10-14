<?php

declare(strict_types=1);

namespace Evolve\Ai\Tests\Unit;

use Evolve\Ai\CancellationSignal;
use Evolve\Ai\InvocationOptions;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class InvocationOptionsTest extends TestCase
{
    public function test_finite_timeout_and_cooperative_signal(): void
    {
        $signal = new class implements CancellationSignal {
            public function isCancellationRequested(): bool
            {
                return true;
            }
        };
        $options = new InvocationOptions(2.5, $signal);
        self::assertSame(2.5, $options->timeoutSeconds);
        self::assertTrue($options->cancellationSignal->isCancellationRequested());
    }

    public function test_nonfinite_timeout_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InvocationOptions(INF);
    }

    public function test_nonpositive_timeout_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InvocationOptions(0.0);
    }
}
