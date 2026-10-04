<?php

declare(strict_types=1);

namespace Evolve\I18n;

use DateTimeZone;
use Evolve\I18n\Exception\InvalidLocalizationPolicy;
use Throwable;

final readonly class LocalizationContext
{
    /** @param non-empty-list<string> $fallbackChain */
    public function __construct(private string $locale, private array $fallbackChain, private string $timezone)
    {
        if (Locale::normalize($locale) !== $locale) {
            throw new InvalidLocalizationPolicy('Localization context locale must be normalized.');
        }
        self::validateFallbackChain($fallbackChain, $locale);
        if ($timezone === '' || trim($timezone) !== $timezone || strlen($timezone) > 255) {
            throw new InvalidLocalizationPolicy('Localization context timezone is invalid.');
        }
        try {
            new DateTimeZone($timezone);
        } catch (Throwable $exception) {
            throw new InvalidLocalizationPolicy('Localization context timezone is invalid.', 0, $exception);
        }
    }

    /** @param array<array-key, mixed> $chain */
    private static function validateFallbackChain(array $chain, string $locale): void
    {
        if ($chain === [] || !array_is_list($chain)) {
            throw new InvalidLocalizationPolicy('Localization context fallback chain must be a non-empty list.');
        }
        $seen = [];
        foreach ($chain as $fallback) {
            if (!is_string($fallback) || Locale::normalize($fallback) !== $fallback || isset($seen[$fallback])) {
                throw new InvalidLocalizationPolicy('Localization context fallback chain is invalid.');
            }
            $seen[$fallback] = true;
        }
        if (!isset($seen[$locale])) {
            throw new InvalidLocalizationPolicy('Localization context locale must occur in its fallback chain.');
        }
    }
    public function locale(): string
    {
        return $this->locale;
    }
    /** @return non-empty-list<string> */
    public function fallbackChain(): array
    {
        return $this->fallbackChain;
    }
    public function timezone(): string
    {
        return $this->timezone;
    }

    public function forLocale(string $locale): self
    {
        return new self($locale, $this->fallbackChain, $this->timezone);
    }
}
