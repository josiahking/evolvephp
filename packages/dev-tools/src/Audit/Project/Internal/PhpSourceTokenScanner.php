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
     * @var array<string, string>
     */
    private const DIRECT_FUNCTION_CATEGORIES = [
        'session_start' => 'native_session_start',
        'setlocale' => 'process_global_mutation',
        'date_default_timezone_set' => 'process_global_mutation',
        'header' => 'response_side_effect',
        'header_remove' => 'response_side_effect',
        'setcookie' => 'response_side_effect',
        'setrawcookie' => 'response_side_effect',
        'ob_start' => 'response_side_effect',
        'ob_clean' => 'response_side_effect',
        'ob_flush' => 'response_side_effect',
        'ob_end_clean' => 'response_side_effect',
        'ob_end_flush' => 'response_side_effect',
        'ob_get_clean' => 'response_side_effect',
        'ob_get_flush' => 'response_side_effect',
        'ob_implicit_flush' => 'response_side_effect',
        'register_shutdown_function' => 'process_lifetime_callback',
    ];

    /**
     * @var array<int, string>
     */
    private const TOKEN_CONSTRUCT_CATEGORIES = [
        T_ECHO => 'response_side_effect',
        T_PRINT => 'response_side_effect',
        T_EXIT => 'process_termination',
        T_EVAL => 'eval',
        T_INCLUDE => 'include_require',
        T_INCLUDE_ONCE => 'include_require',
        T_REQUIRE => 'include_require',
        T_REQUIRE_ONCE => 'include_require',
    ];

    /**
     * @return array{
     *     superglobal_access: list<array{line: int, symbol: string}>,
     *     session_access: list<array{line: int, symbol: string}>,
     *     globals_access: list<array{line: int, symbol: string}>,
     *     global_statement: list<array{line: int, kind: string}>,
     *     static_state_declaration: list<array{line: int, kind: string}>,
     *     static_property_access: list<array{line: int, symbol: string}>,
     *     native_session_start: list<array{line: int, symbol: string}>,
     *     process_global_mutation: list<array{line: int, symbol: string}>,
     *     response_side_effect: list<array{line: int, symbol: string}|array{line: int, kind: string}>,
     *     process_lifetime_callback: list<array{line: int, symbol: string}>,
     *     process_termination: list<array{line: int, kind: string}>,
     *     eval: list<array{line: int, kind: string}>,
     *     include_require: list<array{line: int, kind: string}>
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
            'native_session_start' => [],
            'process_global_mutation' => [],
            'response_side_effect' => [],
            'process_lifetime_callback' => [],
            'process_termination' => [],
            'eval' => [],
            'include_require' => [],
        ];

        foreach ($tokens as $index => $token) {
            if ($token === '\\') {
                $call = $this->rootQualifiedDirectFunctionCall($tokens, $index);

                if ($call !== null) {
                    $evidence[$call['category']][] = ['line' => $call['line'], 'symbol' => $call['symbol']];
                }

                continue;
            }

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

            if (array_key_exists($id, self::TOKEN_CONSTRUCT_CATEGORIES)) {
                $category = self::TOKEN_CONSTRUCT_CATEGORIES[$id];
                $evidence[$category][] = ['line' => $line, 'kind' => strtolower($text)];
            }

            $call = $this->directFunctionCall($tokens, $index);

            if ($call !== null) {
                $evidence[$call['category']][] = ['line' => $call['line'], 'symbol' => $call['symbol']];
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
     * @return array{0: array{0: int, 1: string, 2: int}|string, 1: int}|null
     */
    private function nextSignificantTokenWithIndex(array $tokens, int $offset): ?array
    {
        $count = count($tokens);

        for ($index = $offset; $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return [$token, $index];
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private function previousSignificantToken(array $tokens, int $offset): array|string|null
    {
        return $this->previousSignificantTokenWithIndex($tokens, $offset)[0] ?? null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: array{0: int, 1: string, 2: int}|string, 1: int}|null
     */
    private function previousSignificantTokenWithIndex(array $tokens, int $offset): ?array
    {
        for ($index = $offset; $index >= 0; $index--) {
            $token = $tokens[$index];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return [$token, $index];
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{category: string, line: int, symbol: string}|null
     */
    private function directFunctionCall(array $tokens, int $index): ?array
    {
        $token = $tokens[$index];

        if (! is_array($token)) {
            return null;
        }

        [$id, $text, $line] = $token;
        $symbol = $text;

        if ($id === T_STRING) {
            if (! $this->tokenCanStartFunctionCall($tokens, $index)) {
                return null;
            }
        } elseif ($id === T_NAME_FULLY_QUALIFIED) {
            $symbol = $text;
            $text = ltrim($text, '\\');

            if (str_contains($text, '\\') || ! $this->tokenCanStartFunctionCall($tokens, $index)) {
                return null;
            }
        } else {
            return null;
        }

        $next = $this->nextSignificantTokenWithIndex($tokens, $index + 1);

        if ($next === null || $next[0] !== '(' || $this->isFirstClassCallablePlaceholder($tokens, $next[1])) {
            return null;
        }

        $category = self::DIRECT_FUNCTION_CATEGORIES[strtolower($text)] ?? null;

        if ($category === null) {
            return null;
        }

        return ['category' => $category, 'line' => $line, 'symbol' => $symbol];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{category: string, line: int, symbol: string}|null
     */
    private function rootQualifiedDirectFunctionCall(array $tokens, int $index): ?array
    {
        if (! $this->tokenCanStartFunctionCall($tokens, $index)) {
            return null;
        }

        $next = $this->nextSignificantTokenWithIndex($tokens, $index + 1);

        if ($next === null || ! is_array($next[0]) || $next[0][0] !== T_STRING) {
            return null;
        }

        $afterName = $this->nextSignificantTokenWithIndex($tokens, $next[1] + 1);

        if ($afterName === null || $afterName[0] !== '(' || $this->isFirstClassCallablePlaceholder($tokens, $afterName[1])) {
            return null;
        }

        $name = $next[0][1];
        $category = self::DIRECT_FUNCTION_CATEGORIES[strtolower($name)] ?? null;

        if ($category === null) {
            return null;
        }

        return ['category' => $category, 'line' => $next[0][2], 'symbol' => '\\' . $name];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function tokenCanStartFunctionCall(array $tokens, int $index): bool
    {
        $previous = $this->previousSignificantTokenWithIndex($tokens, $index - 1);
        $previousToken = $previous[0] ?? null;

        if (
            $previous !== null
            && $this->tokenIsAmpersand($previousToken)
            && $this->previousSignificantTokenIsFunction($tokens, $previous[1] - 1)
        ) {
            return false;
        }

        if (is_array($previousToken)) {
            return ! in_array($previousToken[0], [
                T_OBJECT_OPERATOR,
                T_NULLSAFE_OBJECT_OPERATOR,
                T_DOUBLE_COLON,
                T_FUNCTION,
                T_NAME_QUALIFIED,
                T_NAME_FULLY_QUALIFIED,
                T_NAME_RELATIVE,
                T_NEW,
                T_ATTRIBUTE,
            ], true);
        }

        return ! in_array($previousToken, ['\\'], true);
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function previousSignificantTokenIsFunction(array $tokens, int $offset): bool
    {
        $previous = $this->previousSignificantToken($tokens, $offset);

        return is_array($previous) && $previous[0] === T_FUNCTION;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string|null $token
     */
    private function tokenIsAmpersand(array|string|null $token): bool
    {
        return $token === '&'
            || (is_array($token) && in_array($token[0], [
                T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,
                T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG,
            ], true));
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private function isFirstClassCallablePlaceholder(array $tokens, int $openParenthesisIndex): bool
    {
        $first = $this->nextSignificantTokenWithIndex($tokens, $openParenthesisIndex + 1);

        if ($first === null || ! is_array($first[0]) || $first[0][0] !== T_ELLIPSIS) {
            return false;
        }

        $second = $this->nextSignificantTokenWithIndex($tokens, $first[1] + 1);

        return $second !== null && $second[0] === ')';
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
