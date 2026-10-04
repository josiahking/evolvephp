<?php

declare(strict_types=1);

namespace Evolve\I18n;

use Evolve\I18n\Exception\MessageFormattingFailed;
use Stringable;
use Throwable;

final readonly class PlaceholderMessageFormatter implements MessageFormatter
{
    public function format(string $message, LocalizationContext $context, array $parameters = []): string
    {
        $plainText = preg_replace('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', '', $message);
        if ($plainText === null || str_contains($plainText, '{') || str_contains($plainText, '}')) {
            throw new MessageFormattingFailed('Message contains an invalid placeholder.');
        }
        $formatted = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', static function (array $match) use ($parameters): string {
            $key = $match[1];
            if (!array_key_exists($key, $parameters)) {
                throw new MessageFormattingFailed('Required message parameter is missing.');
            }
            $value = $parameters[$key];
            if (is_string($value) || is_int($value) || is_float($value)) {
                return (string) $value;
            }
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            if ($value instanceof Stringable) {
                try {
                    return (string) $value;
                } catch (Throwable $exception) {
                    throw new MessageFormattingFailed('Message parameter could not be formatted.', 0, $exception);
                }
            }
            throw new MessageFormattingFailed('Message parameter has an unsupported type.');
        }, $message);
        if ($formatted === null) {
            throw new MessageFormattingFailed('Message could not be formatted.');
        }
        return $formatted;
    }
}
