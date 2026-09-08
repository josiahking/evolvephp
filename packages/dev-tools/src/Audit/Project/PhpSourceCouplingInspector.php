<?php

declare(strict_types=1);

namespace Evolve\DevTools\Audit\Project;

use Evolve\DevTools\Audit\AuditFinding;
use Evolve\DevTools\Audit\AuditInspector;
use Evolve\DevTools\Audit\AuditSeverity;
use Evolve\DevTools\Audit\Project\Internal\PhpSourceFileFinder;
use Evolve\DevTools\Audit\Project\Internal\PhpSourceTokenScanner;

/**
 * @experimental
 */
final readonly class PhpSourceCouplingInspector implements AuditInspector
{
    /**
     * @param null|callable(string): string|false $sourceReader
     */
    public function __construct(
        private ?PhpSourceFileFinder $finder = null,
        private mixed $sourceReader = null,
        private ?PhpSourceTokenScanner $scanner = null,
    ) {}

    /**
     * @return list<AuditFinding>
     */
    public function inspect(string $projectRoot): array
    {
        $finder = $this->finder ?? new PhpSourceFileFinder();
        $scanner = $this->scanner ?? new PhpSourceTokenScanner();
        $discovery = $finder->find($projectRoot);
        $inspectedPaths = [];
        $skipped = $discovery['skipped'];
        $occurrences = [
            'superglobal_access' => [],
            'session_access' => [],
            'globals_access' => [],
            'global_statement' => [],
            'static_state_declaration' => [],
            'static_property_access' => [],
            'native_session_start' => [],
            'process_global_mutation' => [],
            'response_side_effect' => [],
            'process_lifetime_callback' => [],
            'process_termination' => [],
            'eval' => [],
            'include_require' => [],
        ];

        foreach ($discovery['files'] as $file) {
            $source = $this->readSource($file['absolute_path']);

            if ($source === false) {
                $skipped[] = ['path' => $file['path'], 'reason' => 'unreadable'];

                continue;
            }

            $inspectedPaths[] = $file['path'];
            $fileEvidence = $scanner->scan($source);

            foreach ($fileEvidence as $category => $categoryOccurrences) {
                foreach ($categoryOccurrences as $occurrence) {
                    $occurrences[$category][] = ['path' => $file['path']] + $occurrence;
                }
            }
        }

        sort($inspectedPaths, SORT_STRING);
        usort($skipped, static fn(array $left, array $right): int => [$left['path'], $left['reason']] <=> [$right['path'], $right['reason']]);

        foreach ($occurrences as $category => $categoryOccurrences) {
            usort(
                $categoryOccurrences,
                static fn(array $left, array $right): int => [
                    $left['path'],
                    $left['line'],
                    $left['symbol'] ?? $left['kind'] ?? '',
                ] <=> [
                    $right['path'],
                    $right['line'],
                    $right['symbol'] ?? $right['kind'] ?? '',
                ],
            );
            $occurrences[$category] = $categoryOccurrences;
        }

        $complete = $skipped === [];
        $findings = [
            new AuditFinding('php_source.inventory', $complete ? AuditSeverity::Info : AuditSeverity::Warning, 'PHP source inventory was inspected.', [
                'inspected_count' => count($inspectedPaths),
                'inspected_paths' => $inspectedPaths,
                'complete' => $complete,
                'skipped' => $skipped,
                'sort' => 'path ascending',
            ]),
        ];

        if (! $complete) {
            $findings[] = new AuditFinding('php_source.inspection_incomplete', AuditSeverity::Warning, 'PHP source inspection was incomplete.', [
                'skipped' => $skipped,
                'claim' => 'source analysis incomplete; absence of coupling cannot be claimed for skipped files',
            ]);
        }

        foreach ($this->findingDefinitions() as $category => $definition) {
            if ($occurrences[$category] === []) {
                continue;
            }

            $findings[] = new AuditFinding(
                $definition['identifier'],
                $definition['severity'],
                $definition['message'],
                [
                    'occurrences' => $occurrences[$category],
                    'claim' => $definition['claim'],
                ],
            );
        }

        return $findings;
    }

    private function readSource(string $path): string|false
    {
        if (is_callable($this->sourceReader)) {
            return ($this->sourceReader)($path);
        }

        if (! is_readable($path)) {
            return false;
        }

        return file_get_contents($path);
    }

    /**
     * @return array<string, array{identifier: string, severity: AuditSeverity, message: string, claim: string}>
     */
    private function findingDefinitions(): array
    {
        return [
            'superglobal_access' => [
                'identifier' => 'php_source.superglobal_access',
                'severity' => AuditSeverity::Warning,
                'message' => 'Direct superglobal access was found.',
                'claim' => 'direct lexical superglobal access evidence only',
            ],
            'session_access' => [
                'identifier' => 'php_source.session_access',
                'severity' => AuditSeverity::Risk,
                'message' => 'Direct $_SESSION access was found.',
                'claim' => 'direct native session state access evidence only',
            ],
            'globals_access' => [
                'identifier' => 'php_source.globals_access',
                'severity' => AuditSeverity::Risk,
                'message' => 'Direct $GLOBALS access was found.',
                'claim' => 'direct $GLOBALS access evidence only',
            ],
            'global_statement' => [
                'identifier' => 'php_source.global_statement',
                'severity' => AuditSeverity::Risk,
                'message' => 'Global statement evidence was found.',
                'claim' => 'lexical global statement evidence only',
            ],
            'static_state_declaration' => [
                'identifier' => 'php_source.static_state_declaration',
                'severity' => AuditSeverity::Warning,
                'message' => 'Static state declarations were found and require execution-lifetime review.',
                'claim' => 'static declaration review evidence only; not proof of unsafe mutable execution state',
            ],
            'static_property_access' => [
                'identifier' => 'php_source.static_property_access',
                'severity' => AuditSeverity::Warning,
                'message' => 'Static property access was found and requires execution-lifetime review.',
                'claim' => 'static property access review evidence only; not proof of unsafe mutable execution state',
            ],
            'native_session_start' => [
                'identifier' => 'php_source.native_session_start',
                'severity' => AuditSeverity::Risk,
                'message' => 'Direct native session_start() invocation evidence was found.',
                'claim' => 'direct named lexical native session_start invocation evidence only; not proof that the call executes or that session integration is unsafe',
            ],
            'process_global_mutation' => [
                'identifier' => 'php_source.process_global_mutation',
                'severity' => AuditSeverity::Risk,
                'message' => 'Direct process-global mutation call evidence was found.',
                'claim' => 'direct named lexical process-global mutation review evidence only; not proof that restoration is absent or that the target is persistently unsafe',
            ],
            'response_side_effect' => [
                'identifier' => 'php_source.response_side_effect',
                'severity' => AuditSeverity::Warning,
                'message' => 'Direct response or output side-effect evidence was found.',
                'claim' => 'direct lexical response/output-side-effect review evidence only; not proof that a detected call violates adapter ownership',
            ],
            'process_lifetime_callback' => [
                'identifier' => 'php_source.process_lifetime_callback',
                'severity' => AuditSeverity::Warning,
                'message' => 'Direct process-lifetime callback registration evidence was found.',
                'claim' => 'direct named lexical process-lifetime callback registration evidence only',
            ],
            'process_termination' => [
                'identifier' => 'php_source.process_termination',
                'severity' => AuditSeverity::Risk,
                'message' => 'Lexical process-termination construct evidence was found.',
                'claim' => 'lexical process-termination construct evidence only',
            ],
            'eval' => [
                'identifier' => 'php_source.eval',
                'severity' => AuditSeverity::Risk,
                'message' => 'Lexical eval construct evidence was found.',
                'claim' => 'lexical eval construct evidence only',
            ],
            'include_require' => [
                'identifier' => 'php_source.include_require',
                'severity' => AuditSeverity::Warning,
                'message' => 'Lexical include/require construct evidence was found.',
                'claim' => 'lexical include/require construct evidence only; included paths are not resolved or inspected',
            ],
        ];
    }
}
