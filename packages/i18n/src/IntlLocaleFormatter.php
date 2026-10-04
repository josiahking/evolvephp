<?php

declare(strict_types=1);

namespace Evolve\I18n;

use DateTimeInterface;
use Evolve\I18n\Exception\IntlUnavailable;
use Evolve\I18n\Exception\LocaleFormattingFailed;
use IntlDateFormatter;
use MessageFormatter as PhpMessageFormatter;
use NumberFormatter;
use Throwable;

final readonly class IntlLocaleFormatter implements LocaleFormatter
{
    public function number(int|float $value, LocalizationContext $context): string
    {
        $this->requireIntl();
        try {
            $formatter = NumberFormatter::create($context->locale(), NumberFormatter::DECIMAL);
            $result = $formatter->format($value);
        } catch (Throwable $exception) {
            throw new LocaleFormattingFailed('Number formatter could not be created.', 0, $exception);
        }
        if ($result === false) {
            throw new LocaleFormattingFailed('Number could not be formatted.');
        }
        return $result;
    }

    public function currency(int|float $value, string $currency, LocalizationContext $context): string
    {
        $this->requireIntl();
        if (preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            throw new LocaleFormattingFailed('Invalid currency code.');
        }
        try {
            $formatter = NumberFormatter::create($context->locale(), NumberFormatter::CURRENCY);
            $result = $formatter->formatCurrency((float) $value, $currency);
        } catch (Throwable $exception) {
            throw new LocaleFormattingFailed('Currency formatter could not be created.', 0, $exception);
        }
        if ($result === false) {
            throw new LocaleFormattingFailed('Currency could not be formatted.');
        }
        return $result;
    }

    public function dateTime(DateTimeInterface $value, LocalizationContext $context): string
    {
        $this->requireIntl();
        try {
            $formatter = IntlDateFormatter::create($context->locale(), IntlDateFormatter::MEDIUM, IntlDateFormatter::MEDIUM, $context->timezone());
            if (!$formatter instanceof IntlDateFormatter) {
                throw new LocaleFormattingFailed('Date formatter could not be created.');
            }
            $result = $formatter->format($value->getTimestamp());
        } catch (LocaleFormattingFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new LocaleFormattingFailed('Date and time could not be formatted.', 0, $exception);
        }
        if ($result === false) {
            throw new LocaleFormattingFailed('Date and time could not be formatted.');
        }
        return $result;
    }

    public function unit(int|float $value, string $unit, LocalizationContext $context): string
    {
        $this->requireIntl();
        if (preg_match('/\A[a-z]+(?:-[a-z]+)+\z/D', $unit) !== 1) {
            throw new LocaleFormattingFailed('Invalid ICU measurement unit.');
        }
        try {
            $result = PhpMessageFormatter::formatMessage($context->locale(), '{value, number, ::measure-unit/' . $unit . '}', ['value' => $value]);
        } catch (Throwable $exception) {
            throw new LocaleFormattingFailed('ICU measurement unit is unsupported.', 0, $exception);
        }
        if ($result === false) {
            throw new LocaleFormattingFailed('ICU measurement unit is unsupported.');
        }
        return $result;
    }

    private function requireIntl(): void
    {
        if (!class_exists(NumberFormatter::class) || !class_exists(IntlDateFormatter::class) || !class_exists(PhpMessageFormatter::class)) {
            throw new IntlUnavailable('Intl locale formatting is unavailable.');
        }
    }
}
