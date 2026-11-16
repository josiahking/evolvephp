<?php

declare(strict_types=1);

namespace Evolve\AuditLog;

use Evolve\I18n\LocalizationContext;
use Evolve\I18n\Translator;

/** Presentation-only localization; stored event types remain stable tokens. */
final readonly class AuditEventLabel
{
    public function __construct(private Translator $translator) {}

    public function translate(AuditEvent $event, LocalizationContext $context): string
    {
        return $this->translator->translate('audit-log::' . $event->type, $context);
    }
}