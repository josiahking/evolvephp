<?php

declare(strict_types=1);

namespace Evolve\ApiKeys\Tests\Unit;

use Evolve\ApiKeys\ApiKeyDiagnosticLabel;
use Evolve\I18n\ArrayMessageCatalog;
use Evolve\I18n\CatalogTranslator;
use Evolve\I18n\LocalizationContext;
use Evolve\I18n\PlaceholderMessageFormatter;
use PHPUnit\Framework\TestCase;

final class ApiKeyDiagnosticLabelTest extends TestCase
{
    public function testDenialLabelContainsNoCredential(): void
    {
        $messages = require dirname(__DIR__, 2) . '/resources/lang/en.php';
        $translator = new CatalogTranslator(new ArrayMessageCatalog([
            ['namespace' => 'api-keys', 'locale' => 'en', 'messages' => $messages],
        ]), new PlaceholderMessageFormatter());
        $label = new ApiKeyDiagnosticLabel($translator);
        self::assertSame('API key access was denied.', $label->denied(new LocalizationContext('en', ['en'], 'UTC')));
    }
}
