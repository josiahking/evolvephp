<?php

declare(strict_types=1);

namespace Evolve\I18n\Tests\Unit;

use Evolve\I18n\ArrayMessageCatalog;
use Evolve\I18n\CatalogTranslator;
use Evolve\I18n\Exception\MessageFormattingFailed;
use Evolve\I18n\Exception\MessageNotFound;
use Evolve\I18n\LocalizationContext;
use Evolve\I18n\LocalizationPolicy;
use Evolve\I18n\MessageFormatter;
use Evolve\I18n\PlaceholderMessageFormatter;
use PHPUnit\Framework\TestCase;
use Stringable;

final class TranslatorTest extends TestCase
{
    public function test_malformed_basic_placeholders_fail_instead_of_passing_through(): void
    {
        $formatter = new PlaceholderMessageFormatter();
        $context = (new LocalizationPolicy(['en'], 'en', [], 'UTC'))->context();
        self::assertSame('Plain text.', $formatter->format('Plain text.', $context));
        foreach (['{}', '{name', 'name}', '{name!}', '{user.name}', '{{name}}'] as $message) {
            try {
                $formatter->format($message, $context, ['name' => 'Ada']);
                self::fail('Expected malformed placeholder failure.');
            } catch (MessageFormattingFailed $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_used_resource_and_throwing_stringable_fail_safely(): void
    {
        $formatter = new PlaceholderMessageFormatter();
        $context = (new LocalizationPolicy(['en'], 'en', [], 'UTC'))->context();
        $resource = fopen('php://memory', 'r');
        self::assertIsResource($resource);
        try {
            try {
                $formatter->format('{item}', $context, ['item' => $resource]);
                self::fail('Expected resource rejection.');
            } catch (MessageFormattingFailed $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        } finally {
            fclose($resource);
        }
        $throwing = new class implements Stringable {
            public function __toString(): string
            {
                throw new \RuntimeException('private value');
            }
        };
        try {
            $formatter->format('{item}', $context, ['item' => $throwing]);
            self::fail('Expected Stringable failure.');
        } catch (MessageFormattingFailed $exception) {
            self::assertSame('Message parameter could not be formatted.', $exception->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
        }
    }

    public function test_formatter_receives_matched_catalog_locale_without_mutating_original_context(): void
    {
        $formatter = new class implements MessageFormatter {
            public ?string $locale = null;
            public ?string $timezone = null;
            /** @var list<string> */
            public array $chain = [];

            public function format(string $message, LocalizationContext $context, array $parameters = []): string
            {
                $this->locale = $context->locale();
                $this->timezone = $context->timezone();
                $this->chain = $context->fallbackChain();
                return $message;
            }
        };
        $translator = new CatalogTranslator(new ArrayMessageCatalog([
            ['namespace' => null, 'locale' => 'en', 'messages' => ['files' => 'English catalog']],
        ]), $formatter);
        $context = (new LocalizationPolicy(['ru', 'en'], 'ru', ['en'], 'UTC'))->context('ru', 'Europe/Moscow');

        self::assertSame('English catalog', $translator->translate('files', $context));
        self::assertSame('en', $formatter->locale);
        self::assertSame('Europe/Moscow', $formatter->timezone);
        self::assertSame(['ru', 'en'], $formatter->chain);
        self::assertSame('ru', $context->locale());
    }

    public function test_locale_first_translation_interpolation_and_plain_text(): void
    {
        $catalog = new ArrayMessageCatalog([
            ['namespace' => 'billing', 'locale' => 'en', 'messages' => ['paid' => 'Fallback {number}']],
            ['namespace' => 'billing', 'locale' => 'en-NG', 'messages' => ['paid' => '<b>{number}</b> paid']],
        ]);
        $translator = new CatalogTranslator($catalog, new PlaceholderMessageFormatter());
        $context = (new LocalizationPolicy(['en', 'en-NG'], 'en', [], 'UTC'))->context('en-NG');
        self::assertSame('<b>42</b> paid', $translator->translate('billing::paid', $context, ['number' => 42]));
        $this->expectException(MessageNotFound::class);
        $translator->translate('paid', $context);
    }

    public function test_placeholder_formatter_accepts_scalars_and_stringable_and_rejects_missing_or_unsafe_values(): void
    {
        $formatter = new PlaceholderMessageFormatter();
        $context = (new LocalizationPolicy(['en'], 'en', [], 'UTC'))->context();
        $object = new class implements Stringable {
            public function __toString(): string
            {
                return 'object';
            }
        };
        self::assertSame('a|1|2.5|true|object', $formatter->format('{a}|{b}|{c}|{d}|{e}', $context, ['a' => 'a', 'b' => 1, 'c' => 2.5, 'd' => true, 'e' => $object, 'unused' => []]));
        foreach ([[], ['name' => []], ['name' => new \stdClass()]] as $parameters) {
            try {
                $formatter->format('Hi {name}', $context, $parameters);
                self::fail('Expected formatting failure.');
            } catch (MessageFormattingFailed $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }
}
