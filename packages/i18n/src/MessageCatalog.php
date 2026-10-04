<?php

declare(strict_types=1);

namespace Evolve\I18n;

interface MessageCatalog
{
    public function get(MessageName $name, string $locale): ?string;
}
