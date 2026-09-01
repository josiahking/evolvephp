<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Comparator;

interface PreparedComparatorFixture extends ComparatorFixture
{
    /**
     * @param array<string, mixed> $options
     */
    public function prepareScenario(string $scenarioId, array $options = []): PreparedScenario;
}
