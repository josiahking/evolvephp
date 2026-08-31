<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\PhpBench\Core;

use Evolve\Benchmarks\Support\BenchmarkFixtureFactory;
use Evolve\Core\Execution\ExecutionKind;
use PhpBench\Attributes as Bench;

#[Bench\Revs(50)]
#[Bench\Iterations(10)]
#[Bench\Warmup(2)]
final class ExecutionOrchestratorBench
{
    #[Bench\Groups(['core', 'execution', 'instrumentation'])]
    public function benchSuccessfulExecutionNoSinkZeroResetParticipants(): void
    {
        $fixture = BenchmarkFixtureFactory::executionOrchestratorFixture(resetParticipants: 0);
        $fixture['orchestrator']->execute(ExecutionKind::HttpRequest, $fixture['operation']);
    }

    #[Bench\Groups(['core', 'execution', 'instrumentation'])]
    public function benchSuccessfulExecutionNoOpSinkZeroResetParticipants(): void
    {
        $fixture = BenchmarkFixtureFactory::executionOrchestratorFixture(resetParticipants: 0, withObservationSink: true);
        $fixture['orchestrator']->execute(ExecutionKind::HttpRequest, $fixture['operation']);
    }

    #[Bench\Groups(['core', 'execution', 'reset'])]
    #[Bench\ParamProviders(['resetParticipantCounts'])]
    public function benchSuccessfulExecutionWithResetParticipants(array $params): void
    {
        $fixture = BenchmarkFixtureFactory::executionOrchestratorFixture(resetParticipants: $params['reset_participants']);
        $fixture['orchestrator']->execute(ExecutionKind::HttpRequest, $fixture['operation']);
    }

    /**
     * @return array<string, array{reset_participants: int}>
     */
    public function resetParticipantCounts(): array
    {
        return [
            'one-reset-participant' => ['reset_participants' => 1],
            'ten-reset-participants' => ['reset_participants' => 10],
        ];
    }
}
