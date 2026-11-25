<?php

declare(strict_types=1);

namespace Evolve\ApiKeys;

use Evolve\I18n\LocalizationContext;
use Evolve\I18n\Translator;

/** Safe presentation label; never accepts a credential as a translation parameter. */
final readonly class ApiKeyDiagnosticLabel
{
    public function __construct(private Translator $translator) {}
    public function denied(LocalizationContext $context): string
    {
        return $this->translator->translate('api-keys::denied', $context);
    }
}
