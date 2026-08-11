<?php

declare(strict_types=1);

namespace Evolve\Contracts\Configuration;

interface ConfigurationValidator
{
    public function validate(Configuration $configuration): void;
}
