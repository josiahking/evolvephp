<?php

declare(strict_types=1);

namespace Evolve\DevTools\Audit\Project\Internal;

/**
 * @experimental
 *
 * @internal
 */
final readonly class PhpSourceTokenScanner
{
    /**
     * @return array{
     *     superglobal_access: list<array{line: int, symbol: string}>,
     *     session_access: list<array{line: int, symbol: string}>,
     *     globals_access: list<array{line: int, symbol: string}>,
     *     global_statement: list<array{line: int, kind: string}>,
     *     static_state_declaration: list<array{line: int, kind: string}>,
     *     static_property_access: list<array{line: int, symbol: string}>
     * }
     */
    public function scan(string $source): array
    {
        $tokens = token_get_all($source);
        $evidence = [
            'superglobal_access' => [],
            'session_access' => [],
            'globals_access' => [],
            'global_statement' => [],
            'static_state_declaration' => [],
            'static_property_access' => [],
        ];

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text, $line] = $token;

            if ($id === T_VARIABLE) {
                if (in_array($text, ['$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_FILES', '$_SERVER', '$_ENV'], true)) {
                    $evidence['superglobal_access'][] = ['line' => $line, 'symbol' => $text];
                } elseif ($text === '$_SESSION') {
                    $evidence['session_access'][] = ['line' => $line, 'symbol' => $text];
                } elseif ($text === '$GLOBALS') {
                    $evidence['globals_access'][] = ['line' => $line, 'symbol' => $text];
                }
            }

            if ($id === T_GLOBAL) {
                $evidence['global_statement'][] = ['line' => $line, 'kind' => 'global'];
            }

            if ($id === T_STATIC) {
                if ($this->staticTokenStartsStateDeclaration($tokens, $index + 1)) {
                    $evidence['static_state_declaration'][] = ['line' => $line, 'kind' => 'static_variable'];
                }
            }

            if ($id === T_DOUBLE_COLON) {
                $next = $this->nextSignificantToken($tokens, $index + 1);

                if (is_array($next) && $next[0] === T_VARIABLE) {
                    $evidence['static_property_access'][] = ['line' => $next[2], 'symbol' => $next[1]];
                }
            }
        }

        return $evidence;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private function nextSignificantToken(array $tokens, int $offset): array|string|null
    {
        $count = count($tokens);

        for ($index = $offset; $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function staticTokenStartsStateDeclaration(array $tokens, int $offset): bool
    {
        $count = count($tokens);

        for ($index = $offset; $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (is_array($token)) {
                if ($token[0] === T_VARIABLE) {
                    return true;
                }

                if ($token[0] === T_FUNCTION || $token[0] === T_FN) {
                    return false;
                }

                if (in_array($token[0], [
                    T_STRING,
                    T_NAME_FULLY_QUALIFIED,
                    T_NAME_QUALIFIED,
                    T_NAME_RELATIVE,
                    T_ARRAY,
                    T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG,
                ], true)) {
                    continue;
                }

                return false;
            }

            if (in_array($token, ['?', '|', '&', '(', ')'], true)) {
                continue;
            }

            return false;
        }

        return false;
    }
}
