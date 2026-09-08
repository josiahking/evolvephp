<?php

declare(strict_types=1);

namespace Evolve\DevTools\Audit;

use InvalidArgumentException;

/**
 * @experimental
 */
final readonly class AuditRunner
{
    /**
     * @var list<AuditInspector>
     */
    private array $inspectors;

    /**
     * @param iterable<mixed> $inspectors
     */
    public function __construct(iterable $inspectors)
    {
        $orderedInspectors = [];

        foreach ($inspectors as $inspector) {
            if (! $inspector instanceof AuditInspector) {
                throw new InvalidArgumentException('Audit runner accepts only audit inspectors.');
            }

            $orderedInspectors[] = $inspector;
        }

        $this->inspectors = $orderedInspectors;
    }

    public function inspect(string $projectRoot): AuditReport
    {
        $realProjectRoot = realpath($projectRoot);

        if ($realProjectRoot === false || ! is_dir($realProjectRoot)) {
            throw new InvalidArgumentException('Audit target root must be an existing directory.');
        }

        $findings = [];

        foreach ($this->inspectors as $inspector) {
            $inspectorFindings = $inspector->inspect($realProjectRoot);

            if (! is_iterable($inspectorFindings)) {
                throw new InvalidArgumentException('Audit inspectors must return iterable findings.');
            }

            foreach ($inspectorFindings as $finding) {
                if (! $finding instanceof AuditFinding) {
                    throw new InvalidArgumentException('Audit inspectors must return audit findings.');
                }

                $findings[] = $finding;
            }
        }

        return new AuditReport($findings);
    }
}
