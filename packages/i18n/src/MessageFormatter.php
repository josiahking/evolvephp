<?php

declare(strict_types=1);

namespace Evolve\I18n;

interface MessageFormatter
{
    /** @param array<string, mixed> $parameters */
    public function format(string $message, LocalizationContext $context, array $parameters = []): string;
}
