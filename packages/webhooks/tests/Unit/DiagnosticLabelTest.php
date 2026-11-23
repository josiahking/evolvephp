<?php

declare(strict_types=1);

namespace Evolve\Webhooks\Tests\Unit;

require_once __DIR__ . '/../../src/WebhookDiagnosticLabel.php';

use Evolve\I18n\ArrayMessageCatalog;
use Evolve\I18n\CatalogTranslator;
use Evolve\I18n\LocalizationContext;
use Evolve\I18n\PlaceholderMessageFormatter;
use Evolve\Webhooks\WebhookDiagnosticLabel;
use PHPUnit\Framework\TestCase;

final class DiagnosticLabelTest extends TestCase
{
    public function testModuleNamespacedDiagnosticUsesExplicitLocale(): void
    {
        $messages = require dirname(__DIR__, 2) . '/resources/lang/en.php';
        $translator = new CatalogTranslator(new ArrayMessageCatalog([
            ['namespace' => 'webhooks', 'locale' => 'en', 'messages' => $messages],
        ]), new PlaceholderMessageFormatter());
        $label = new WebhookDiagnosticLabel($translator);
        self::assertSame('Webhook delivery was acknowledged.', $label->translate('delivery.acknowledged', new LocalizationContext('en', ['en'], 'UTC')));
    }
}
