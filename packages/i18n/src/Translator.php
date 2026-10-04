<?php

declare(strict_types=1);

namespace Evolve\I18n;

interface Translator
{
    /** @param array<string, mixed> $parameters */
    public function translate(string $message, LocalizationContext $context, array $parameters = []): string;
}
