<?php

declare(strict_types=1);

namespace Evolve\Ai\Tests\Integration;

use Evolve\Ai\InvocationOptions;
use Evolve\Ai\ModelMessage;
use Evolve\Ai\ModelProvider;
use Evolve\Ai\ModelRequest;
use Evolve\Ai\ModelResponse;
use Evolve\Ai\ModelRole;
use Evolve\Ai\TextPart;
use Evolve\Ai\Usage;
use PHPUnit\Framework\TestCase;

final class ProviderNeutralInvocationTest extends TestCase
{
    public function test_application_owned_provider_invokes_without_sdk_or_network(): void
    {
        $provider = new class implements ModelProvider {
            public function invoke(ModelRequest $request, InvocationOptions $options): ModelResponse
            {
                return new ModelResponse([new TextPart('Local answer')], new Usage(2, 3));
            }
        };
        $result = $provider->invoke(
            new ModelRequest([new ModelMessage(ModelRole::User, [new TextPart('Hello')])]),
            new InvocationOptions(3.0),
        );
        self::assertSame('Local answer', $result->parts[0]->text);
        self::assertSame(5, $result->usage->totalTokens);
    }

    public function test_adapter_can_cooperatively_cancel_before_and_during_work(): void
    {
        $signal = new class implements \Evolve\Ai\CancellationSignal {
            public bool $cancelled = true;

            public function isCancellationRequested(): bool
            {
                return $this->cancelled;
            }
        };
        $provider = new class (static function () use ($signal): void {
            $signal->cancelled = true;
        }) implements ModelProvider {
            /** @param \Closure(): void $duringWork */
            public function __construct(private \Closure $duringWork) {}

            public function invoke(ModelRequest $request, InvocationOptions $options): ModelResponse
            {
                if ($options->cancellationSignal?->isCancellationRequested()) {
                    throw new \Evolve\Ai\ModelInvocationException(\Evolve\Ai\ModelFailureCategory::Cancelled);
                }
                ($this->duringWork)();
                if ($options->cancellationSignal?->isCancellationRequested()) {
                    throw new \Evolve\Ai\ModelInvocationException(\Evolve\Ai\ModelFailureCategory::Cancelled);
                }

                return new ModelResponse([new TextPart('Done')]);
            }
        };
        $request = new ModelRequest([new ModelMessage(ModelRole::User, [new TextPart('Hello')])]);
        try {
            $provider->invoke($request, new InvocationOptions(3.0, $signal));
            self::fail('Expected pre-execution cancellation.');
        } catch (\Evolve\Ai\ModelInvocationException $error) {
            self::assertSame(\Evolve\Ai\ModelFailureCategory::Cancelled, $error->category);
        }
        $signal->cancelled = false;
        try {
            $provider->invoke($request, new InvocationOptions(3.0, $signal));
            self::fail('Expected during-execution cancellation.');
        } catch (\Evolve\Ai\ModelInvocationException $error) {
            self::assertSame(\Evolve\Ai\ModelFailureCategory::Cancelled, $error->category);
        }
    }
}
