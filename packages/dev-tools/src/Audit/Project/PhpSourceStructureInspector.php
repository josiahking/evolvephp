<?php

declare(strict_types=1);

namespace Evolve\DevTools\Audit\Project;

use Evolve\DevTools\Audit\AuditFinding;
use Evolve\DevTools\Audit\AuditInspector;
use Evolve\DevTools\Audit\AuditSeverity;
use Evolve\DevTools\Audit\Project\Internal\PhpSourceFileFinder;

/**
 * @experimental
 */
final readonly class PhpSourceStructureInspector implements AuditInspector
{
    private const GLOBAL_NAMESPACE = '<global>';

    /**
     * @param null|callable(string): string|false $sourceReader
     */
    public function __construct(
        private ?PhpSourceFileFinder $finder = null,
        private mixed $sourceReader = null,
    ) {}

    /**
     * @return list<AuditFinding>
     */
    public function inspect(string $projectRoot): array
    {
        $finder = $this->finder ?? new PhpSourceFileFinder();
        $discovery = $finder->find($projectRoot);
        $inspectedPaths = [];
        $skipped = $discovery['skipped'];
        $declarations = [];
        $namespaceDeclarations = [];
        $namespaceCountsByPath = [];

        foreach ($discovery['files'] as $file) {
            $source = $this->readSource($file['absolute_path']);

            if ($source === false) {
                $skipped[] = ['path' => $file['path'], 'reason' => 'unreadable'];

                continue;
            }

            $inspectedPaths[] = $file['path'];
            $fileEvidence = $this->scan($source, $file['path']);
            array_push($declarations, ...$fileEvidence['declarations']);
            array_push($namespaceDeclarations, ...$fileEvidence['namespace_declarations']);
            $namespaceCountsByPath[$file['path']] = count($fileEvidence['namespace_declarations']);
        }

        sort($inspectedPaths, SORT_STRING);
        usort($skipped, static fn(array $left, array $right): int => [$left['path'], $left['reason']] <=> [$right['path'], $right['reason']]);
        usort($declarations, static fn(array $left, array $right): int => [$left['path'], $left['line'], $left['kind'], $left['name']] <=> [$right['path'], $right['line'], $right['kind'], $right['name']]);
        usort($namespaceDeclarations, static fn(array $left, array $right): int => [$left['path'], $left['line'], $left['namespace']] <=> [$right['path'], $right['line'], $right['namespace']]);

        $multipleNamespaceCounts = [];

        foreach ($namespaceCountsByPath as $path => $count) {
            if ($count > 1) {
                $multipleNamespaceCounts[$path] = $count;
            }
        }

        ksort($multipleNamespaceCounts, SORT_STRING);
        $complete = $skipped === [];
        $findings = [
            new AuditFinding('php_source.structure', $complete ? AuditSeverity::Info : AuditSeverity::Warning, 'PHP source structure was inspected.', [
                'inspected_count' => count($inspectedPaths),
                'inspected_paths' => $inspectedPaths,
                'complete' => $complete,
                'skipped' => $skipped,
                'declarations' => $declarations,
                'namespace_declarations' => $namespaceDeclarations,
                'files_with_multiple_namespaces' => array_keys($multipleNamespaceCounts),
                'sort' => 'path, line, kind, name ascending',
                'claim' => 'lexical PHP namespace and named-declaration evidence only; source is not executed',
            ]),
        ];

        if (! $complete) {
            $findings[] = new AuditFinding('php_source.structure_incomplete', AuditSeverity::Warning, 'PHP source structure inspection was incomplete.', [
                'skipped' => $skipped,
                'claim' => 'source-structure analysis incomplete; absence of declarations cannot be claimed for skipped files',
            ]);
        }

        $findings[] = $this->sourceSignalsFinding($declarations, $multipleNamespaceCounts, $complete);

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
     * @return array{declarations: list<array{path: string, line: int, kind: string, name: string, namespace: string}>, namespace_declarations: list<array{path: string, line: int, namespace: string}>}
     */
    private function scan(string $source, string $path): array
    {
        $tokens = token_get_all($source);
        $namespace = self::GLOBAL_NAMESPACE;
        $namespaceDeclarations = [];
        $declarations = [];
        $classBraceDepths = [];
        $classBodyOpenIndexes = [];
        $braceDepth = 0;

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            $id = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;
            $line = is_array($token) ? $token[2] : 0;

            if ($text === '{') {
                $braceDepth++;

                if (isset($classBodyOpenIndexes[$i])) {
                    $classBraceDepths[] = $braceDepth;
                }

                continue;
            }

            if ($text === '}') {
                while ($classBraceDepths !== [] && end($classBraceDepths) === $braceDepth) {
                    array_pop($classBraceDepths);
                }

                $braceDepth = max(0, $braceDepth - 1);

                continue;
            }

            if ($id === T_NAMESPACE) {
                $namespaceEvidence = $this->parseNamespace($tokens, $i);
                $namespace = $namespaceEvidence['namespace'];
                $namespaceDeclarations[] = ['path' => $path, 'line' => $line, 'namespace' => $namespace];
                $i = $namespaceEvidence['index'];

                continue;
            }

            if ($id === T_CLASS && $this->isAnonymousClass($tokens, $i)) {
                $classBodyOpenIndex = $this->classBodyOpenIndex($tokens, $i);

                if ($classBodyOpenIndex !== null) {
                    $classBodyOpenIndexes[$classBodyOpenIndex] = true;
                }

                continue;
            }

            $kind = match ($id) {
                T_CLASS => 'class',
                T_INTERFACE => 'interface',
                T_TRAIT => 'trait',
                defined('T_ENUM') ? T_ENUM : -1 => 'enum',
                default => null,
            };

            if ($kind !== null) {
                $name = $this->nextDeclarationName($tokens, $i);

                if ($name !== null) {
                    $declarations[] = ['path' => $path, 'line' => $line, 'kind' => $kind, 'name' => $name, 'namespace' => $namespace];
                    $classBodyOpenIndex = $this->classBodyOpenIndex($tokens, $i);

                    if ($classBodyOpenIndex !== null) {
                        $classBodyOpenIndexes[$classBodyOpenIndex] = true;
                    }
                }

                continue;
            }

            if ($id === T_FUNCTION && $classBraceDepths === []) {
                $name = $this->nextFunctionName($tokens, $i);

                if ($name !== null) {
                    $declarations[] = ['path' => $path, 'line' => $line, 'kind' => 'function', 'name' => $name, 'namespace' => $namespace];
                }
            }
        }

        return ['declarations' => $declarations, 'namespace_declarations' => $namespaceDeclarations];
    }

    /**
     * @param list<mixed> $tokens
     * @return array{namespace: string, index: int}
     */
    private function parseNamespace(array $tokens, int $index): array
    {
        $parts = [];

        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;
            $id = is_array($token) ? $token[0] : null;

            if ($text === ';' || $text === '{') {
                return [
                    'namespace' => $parts === [] ? self::GLOBAL_NAMESPACE : implode('', $parts),
                    'index' => $i - ($text === '{' ? 1 : 0),
                ];
            }

            if ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NS_SEPARATOR) {
                $parts[] = $text;
            }
        }

        return ['namespace' => $parts === [] ? self::GLOBAL_NAMESPACE : implode('', $parts), 'index' => $index];
    }

    /**
     * @param list<mixed> $tokens
     */
    private function nextDeclarationName(array $tokens, int $index): ?string
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_string($token)) {
                return null;
            }

            if ($this->isIgnorable($token[0])) {
                continue;
            }

            return $token[0] === T_STRING ? $token[1] : null;
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function nextFunctionName(array $tokens, int $index): ?string
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '&') {
                continue;
            }

            if (is_array($token) && $this->isIgnorable($token[0])) {
                continue;
            }

            if (is_array($token) && $this->isAmpersandToken($token[0])) {
                continue;
            }

            return is_array($token) && $token[0] === T_STRING ? $token[1] : null;
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function isAnonymousClass(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (is_array($token) && $this->isIgnorable($token[0])) {
                continue;
            }

            if ($token === ']') {
                $attributeStart = $this->previousAttributeStart($tokens, $i);

                if ($attributeStart !== null) {
                    $i = $attributeStart;

                    continue;
                }
            }

            if (is_array($token) && $token[0] === T_READONLY) {
                continue;
            }

            return is_array($token) && $token[0] === T_NEW;
        }

        return false;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function classBodyOpenIndex(array $tokens, int $index): ?int
    {
        $parentheses = 0;
        $brackets = 0;

        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '(') {
                $parentheses++;

                continue;
            }

            if ($text === ')') {
                $parentheses = max(0, $parentheses - 1);

                continue;
            }

            if ($text === '[') {
                $brackets++;

                continue;
            }

            if ($text === ']') {
                $brackets = max(0, $brackets - 1);

                continue;
            }

            if ($text === '{' && $parentheses === 0 && $brackets === 0) {
                return $i;
            }

            if ($text === ';' && $parentheses === 0 && $brackets === 0) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function previousAttributeStart(array $tokens, int $index): ?int
    {
        $depth = 1;

        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if ($token === ']') {
                $depth++;

                continue;
            }

            if ($token === '[') {
                $depth--;

                continue;
            }

            if (is_array($token) && $token[0] === T_ATTRIBUTE) {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    private function isIgnorable(int $tokenId): bool
    {
        return in_array($tokenId, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_ATTRIBUTE], true);
    }

    private function isAmpersandToken(int $tokenId): bool
    {
        return (defined('T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG') && $tokenId === T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG)
            || (defined('T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG') && $tokenId === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG);
    }

    /**
     * @param list<array{path: string, line: int, kind: string, name: string, namespace: string}> $declarations
     * @param array<string, int> $multipleNamespaceCounts
     */
    private function sourceSignalsFinding(array $declarations, array $multipleNamespaceCounts, bool $complete): AuditFinding
    {
        $groups = [];
        $reviewSignals = [];

        foreach ($declarations as $declaration) {
            if ($declaration['namespace'] === self::GLOBAL_NAMESPACE) {
                $reviewSignals[] = [
                    'kind' => 'global_named_declaration',
                    'path' => $declaration['path'],
                    'line' => $declaration['line'],
                    'declaration_kind' => $declaration['kind'],
                    'name' => $declaration['name'],
                ];

                continue;
            }

            $namespace = $declaration['namespace'];
            $groups[$namespace] ??= [
                'kind' => 'namespace_group',
                'namespace' => $namespace,
                'declaration_count' => 0,
                'declaration_kinds' => [],
                'paths' => [],
            ];
            $groups[$namespace]['declaration_count']++;
            $groups[$namespace]['declaration_kinds'][$declaration['kind']] = ($groups[$namespace]['declaration_kinds'][$declaration['kind']] ?? 0) + 1;
            $groups[$namespace]['paths'][$declaration['path']] = true;
        }

        ksort($groups, SORT_STRING);
        $namespaceCandidates = [];

        foreach ($groups as $group) {
            ksort($group['declaration_kinds'], SORT_STRING);
            $paths = array_keys($group['paths']);
            sort($paths, SORT_STRING);
            $group['paths'] = $paths;
            $namespaceCandidates[] = $group;
        }

        foreach ($multipleNamespaceCounts as $path => $count) {
            $reviewSignals[] = [
                'kind' => 'multiple_namespace_declarations',
                'path' => $path,
                'namespace_count' => $count,
            ];
        }

        usort($reviewSignals, static fn(array $left, array $right): int => [$left['path'], $left['kind'], $left['line'] ?? 0] <=> [$right['path'], $right['kind'], $right['line'] ?? 0]);

        $hasSignals = $namespaceCandidates !== [] || $reviewSignals !== [];

        return new AuditFinding('modernization.source_signals', $hasSignals ? AuditSeverity::Warning : AuditSeverity::Info, 'PHP source modernization review signals were inspected.', [
            'complete' => $complete,
            'namespace_candidates' => $namespaceCandidates,
            'review_signals' => $reviewSignals,
            'claim' => 'lexical source-structure review signals only; not proof of module or capability boundaries, runtime execution, migration feasibility, Bridge compatibility, or migration readiness',
        ]);
    }
}
