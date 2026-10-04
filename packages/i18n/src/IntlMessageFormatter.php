<?php

declare(strict_types=1);

namespace Evolve\I18n;

use Evolve\I18n\Exception\IntlUnavailable;
use Evolve\I18n\Exception\MessageFormattingFailed;
use MessageFormatter as PhpMessageFormatter;
use Throwable;

final readonly class IntlMessageFormatter implements MessageFormatter
{
    public function format(string $message, LocalizationContext $context, array $parameters = []): string
    {
        if (!class_exists(PhpMessageFormatter::class)) {
            throw new IntlUnavailable('Intl message formatting is unavailable.');
        }
        try {
            $formatter = PhpMessageFormatter::create($context->locale(), $message);
            $result = $formatter->format($parameters);
        } catch (Throwable $exception) {
            throw new MessageFormattingFailed('ICU message could not be formatted.', 0, $exception);
        }
        if ($result === false) {
            throw new MessageFormattingFailed('ICU message could not be formatted.');
        }
        return $result;
    }
}
