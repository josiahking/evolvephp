<?php

declare(strict_types=1);

namespace Evolve\Webhooks;

use Evolve\I18n\LocalizationContext;
use Evolve\I18n\Translator;

/** Presentation-only diagnostics; stored delivery state uses stable tokens. */
final readonly class WebhookDiagnosticLabel
{
    public function __construct(private Translator $translator) {}

    public function translate(string $code, LocalizationContext $context): string
    {
        return $this->translator->translate('webhooks::' . $code, $context);
    }
}
