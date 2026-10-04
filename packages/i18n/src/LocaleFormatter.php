<?php

declare(strict_types=1);

namespace Evolve\I18n;

use DateTimeInterface;

interface LocaleFormatter
{
    public function number(int|float $value, LocalizationContext $context): string;
    public function currency(int|float $value, string $currency, LocalizationContext $context): string;
    public function dateTime(DateTimeInterface $value, LocalizationContext $context): string;
    public function unit(int|float $value, string $unit, LocalizationContext $context): string;
}
