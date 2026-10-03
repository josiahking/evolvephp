<?php

declare(strict_types=1);

namespace Evolve\I18n\Tests\Unit;

use DateTimeZone;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionContextValues;
use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\I18n\ArrayMessageCatalog;
use Evolve\I18n\CatalogTranslator;
use Evolve\I18n\Exception\InvalidLocalizationPolicy;
use Evolve\I18n\ExecutionLocalizationContextFactory;
use Evolve\I18n\LocalizationContext;
use Evolve\I18n\LocalizationPolicy;
use Evolve\I18n\PlaceholderMessageFormatter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use WeakReference;

final class LocalizationPolicyTest extends TestCase
{
    public function test_execution_offset_timezone_accepted_by_core_is_preserved_without_global_change(): void
    {
        $timezoneBefore = date_default_timezone_get();
        $values = new ExecutionContextValues('en', '+02:00');
        self::assertSame('+02:00', $values->timezone());
        self::assertNotContains('+02:00', DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC));

        $execution = new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::HttpRequest, $values);
        $context = (new ExecutionLocalizationContextFactory(new LocalizationPolicy(['en'], 'en', [], 'UTC')))->fromExecution($execution);
        self::assertSame('+02:00', $context->timezone());
        self::assertSame($timezoneBefore, date_default_timezone_get());
    }

    public function test_public_context_constructor_rejects_invalid_value_state(): void
    {
        $constructor = new ReflectionClass(LocalizationContext::class);
        foreach ([
            ['en', [], 'UTC'],
            ['bad/locale', ['en'], 'UTC'],
            ['en_NG', ['en-NG'], 'UTC'],
            ['en', ['EN'], 'UTC'],
            ['en', ['fr'], 'UTC'],
            ['en', ['en', 'en'], 'UTC'],
            ['en', [1 => 'en'], 'UTC'],
            ['en', ['en'], 'Bad/Zone'],
        ] as $values) {
            try {
                $constructor->newInstanceArgs($values);
                self::fail('Expected invalid localization context.');
            } catch (InvalidLocalizationPolicy $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_localization_does_not_change_process_locale_or_timezone(): void
    {
        $localeBefore = setlocale(LC_ALL, '0');
        $timezoneBefore = date_default_timezone_get();
        $intlBefore = class_exists(\Locale::class) ? \Locale::getDefault() : null;
        $policy = new LocalizationPolicy(['en-NG', 'fr-FR'], 'en-NG', ['fr-FR'], 'Africa/Lagos');
        $context = $policy->context('fr_FR', 'Europe/Paris');
        $translator = new CatalogTranslator(new ArrayMessageCatalog([['namespace' => null, 'locale' => 'fr-FR', 'messages' => ['hello' => 'Bonjour {name}']]]), new PlaceholderMessageFormatter());
        self::assertSame('Bonjour Ada', $translator->translate('hello', $context, ['name' => 'Ada']));
        self::assertSame($localeBefore, setlocale(LC_ALL, '0'));
        self::assertSame($timezoneBefore, date_default_timezone_get());
        if ($intlBefore !== null) {
            self::assertSame($intlBefore, \Locale::getDefault());
        }
    }

    public function test_normalization_parent_and_fallback_order(): void
    {
        $policy = new LocalizationPolicy(['en', 'en_NG', 'zh_Hant', 'fr_FR', 'EN-ng'], 'en-NG', ['fr_fr', 'en'], 'Africa/Lagos');
        self::assertSame(['en', 'en-NG', 'zh-Hant', 'fr-FR'], $policy->supportedLocales());
        self::assertSame(['zh-Hant', 'fr-FR', 'en', 'en-NG'], $policy->fallbackChain('ZH_hant_TW'));
        self::assertSame(['en-NG', 'fr-FR', 'en'], $policy->fallbackChain(null));
        self::assertSame(['fr-FR', 'en', 'en-NG'], $policy->fallbackChain('fr_ca'));
    }

    public function test_invalid_policy_values_are_rejected(): void
    {
        foreach ([
            [['en'], 'fr', [], 'UTC'],
            [['en'], 'en', ['fr'], 'UTC'],
            [['en'], 'en', [], 'Bad/Zone'],
            [['en/../fr'], 'en', [], 'UTC'],
        ] as [$supported, $default, $fallbacks, $timezone]) {
            try {
                new LocalizationPolicy($supported, $default, $fallbacks, $timezone);
                self::fail('Expected invalid policy.');
            } catch (InvalidLocalizationPolicy $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_execution_binding_is_value_only_and_does_not_retain_execution(): void
    {
        $factory = new ExecutionLocalizationContextFactory(new LocalizationPolicy(['en', 'fr-FR'], 'en', [], 'UTC'));
        $execution = new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::HttpRequest, new ExecutionContextValues('fr_fr', 'Europe/Paris'));
        $reference = WeakReference::create($execution);
        $localized = $factory->fromExecution($execution);
        self::assertSame('fr-FR', $localized->locale());
        self::assertSame(['fr-FR', 'en'], $localized->fallbackChain());
        self::assertSame('Europe/Paris', $localized->timezone());
        unset($execution);
        self::assertNull($reference->get());
        $default = $factory->fromExecution(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::HttpRequest));
        self::assertSame('en', $default->locale());
        self::assertSame('UTC', $default->timezone());
    }
}
