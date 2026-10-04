<?php

declare(strict_types=1);

namespace Evolve\I18n\Tests\Unit;

use DateTimeImmutable;
use Evolve\I18n\ArrayMessageCatalog;
use Evolve\I18n\CatalogTranslator;
use Evolve\I18n\Exception\IntlUnavailable;
use Evolve\I18n\Exception\LocaleFormattingFailed;
use Evolve\I18n\IntlLocaleFormatter;
use Evolve\I18n\IntlMessageFormatter;
use Evolve\I18n\LocalizationPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class IntlFormattingTest extends TestCase
{
    public function test_fallback_icu_plural_uses_catalog_language_rules_when_available(): void
    {
        if (!extension_loaded('intl')) {
            self::markTestSkipped('ext-intl is unavailable in the local PHP runtime.');
        }
        $context = (new LocalizationPolicy(['ru', 'en'], 'ru', ['en'], 'UTC'))->context('ru');
        $translator = new CatalogTranslator(new ArrayMessageCatalog([
            ['namespace' => null, 'locale' => 'en', 'messages' => ['files' => '{count, plural, one {one} few {few} other {other}}']],
        ]), new IntlMessageFormatter());
        self::assertSame('other', $translator->translate('files', $context, ['count' => 2]));
        self::assertSame('ru', $context->locale());
    }

    public function test_explicit_intl_capability_or_deterministic_unavailable_error(): void
    {
        $context = (new LocalizationPolicy(['en'], 'en', [], 'UTC'))->context();
        if (!extension_loaded('intl')) {
            foreach ([
                static fn() => (new IntlMessageFormatter())->format('Hello {name}', $context, ['name' => 'Ada']),
                static fn() => (new IntlLocaleFormatter())->number(2, $context),
                static fn() => (new IntlLocaleFormatter())->currency(2, 'USD', $context),
                static fn() => (new IntlLocaleFormatter())->dateTime(new DateTimeImmutable('2024-01-01'), $context),
                static fn() => (new IntlLocaleFormatter())->unit(2, 'length-meter', $context),
            ] as $operation) {
                try {
                    $operation();
                    self::fail('Expected IntlUnavailable.');
                } catch (IntlUnavailable $exception) {
                    self::assertNotSame('', $exception->getMessage());
                }
            }
            return;
        }
        self::assertSame('2 files', (new IntlMessageFormatter())->format('{count, plural, one {# file} other {# files}}', $context, ['count' => 2]));
        self::assertSame('she', (new IntlMessageFormatter())->format('{gender, select, female {she} male {he} other {they}}', $context, ['gender' => 'female']));
    }

    public function test_intl_locale_number_currency_date_and_unit_when_available(): void
    {
        if (!extension_loaded('intl')) {
            self::markTestSkipped('ext-intl is unavailable in the local PHP runtime.');
        }
        $formatter = new IntlLocaleFormatter();
        $context = (new LocalizationPolicy(['en-US'], 'en-US', [], 'UTC'))->context(null, 'America/New_York');
        self::assertNotSame('', $formatter->number(1234.5, $context));
        self::assertStringContainsString('$', $formatter->currency(12.5, 'USD', $context));
        self::assertStringContainsString('2024', $formatter->dateTime(new DateTimeImmutable('2024-01-01T12:00:00+00:00'), $context));
        $utc = (new LocalizationPolicy(['en-US'], 'en-US', [], 'UTC'))->context();
        self::assertNotSame($formatter->dateTime(new DateTimeImmutable('2024-01-01T12:00:00+00:00'), $utc), $formatter->dateTime(new DateTimeImmutable('2024-01-01T12:00:00+00:00'), $context));
        self::assertNotSame('', $formatter->unit(3, 'length-meter', $context));
    }

    public function test_intl_date_failure_wraps_native_exception_when_available(): void
    {
        if (!extension_loaded('intl')) {
            self::markTestSkipped('ext-intl is unavailable in the local PHP runtime.');
        }
        $date = new class extends DateTimeImmutable {
            public function getTimestamp(): int
            {
                throw new RuntimeException('private timestamp failure');
            }
        };
        $context = (new LocalizationPolicy(['en'], 'en', [], 'UTC'))->context();
        try {
            (new IntlLocaleFormatter())->dateTime($date, $context);
            self::fail('Expected locale formatting failure.');
        } catch (LocaleFormattingFailed $exception) {
            self::assertSame('Date and time could not be formatted.', $exception->getMessage());
            self::assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }
    }

    public function test_invalid_currency_and_measurement_unit_fail_safely_when_available(): void
    {
        if (!extension_loaded('intl')) {
            self::markTestSkipped('ext-intl is unavailable in the local PHP runtime.');
        }
        $formatter = new IntlLocaleFormatter();
        $context = (new LocalizationPolicy(['en'], 'en', [], 'UTC'))->context();
        foreach ([static fn() => $formatter->currency(2, 'usd', $context), static fn() => $formatter->unit(2, 'bad/unit', $context), static fn() => $formatter->unit(2, 'unsupported-unit', $context)] as $operation) {
            try {
                $operation();
                self::fail('Expected locale formatting failure.');
            } catch (LocaleFormattingFailed $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }
}
