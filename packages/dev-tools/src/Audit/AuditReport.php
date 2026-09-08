<?php

declare(strict_types=1);

namespace Evolve\DevTools\Audit;

use ArrayIterator;
use Countable;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * @experimental
 *
 * @implements IteratorAggregate<int, AuditFinding>
 */
final readonly class AuditReport implements Countable, IteratorAggregate
{
    /**
     * @var list<AuditFinding>
     */
    private array $findings;

    /**
     * @param iterable<mixed> $findings
     */
    public function __construct(iterable $findings)
    {
        $orderedFindings = [];

        foreach ($findings as $finding) {
            if (! $finding instanceof AuditFinding) {
                throw new InvalidArgumentException('Audit reports may contain only audit findings.');
            }

            $orderedFindings[] = $finding;
        }

        $this->findings = $orderedFindings;
    }

    /**
     * @return list<AuditFinding>
     */
    public function findings(): array
    {
        return $this->findings;
    }

    public function count(): int
    {
        return count($this->findings);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->findings);
    }
}
