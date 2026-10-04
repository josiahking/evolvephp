<?php

declare(strict_types=1);

namespace Evolve\I18n;

use DateTimeZone;
use Evolve\I18n\Exception\InvalidLocalizationPolicy;
use Throwable;

final readonly class LocalizationPolicy
{
    /** @var list<string> */
    private array $supported;
    /** @var list<string> */
    private array $fallbacks;
    private string $default;

    /**
     * @param array<array-key, mixed> $supportedLocales
     * @param array<array-key, mixed> $fallbackLocales
     */
    public function __construct(array $supportedLocales, string $defaultLocale, array $fallbackLocales, private string $defaultTimezone)
    {
        if (!array_is_list($supportedLocales) || !array_is_list($fallbackLocales) || $supportedLocales === []) {
            throw new InvalidLocalizationPolicy('Locale lists must be nonempty where required and sequential.');
        }
        $supported = [];
        foreach ($supportedLocales as $locale) {
            if (!is_string($locale)) {
                throw new InvalidLocalizationPolicy('Supported locales must be strings.');
            }
            $supported[] = Locale::normalize($locale);
        }
        $this->supported = array_values(array_unique($supported));
        $this->default = Locale::normalize($defaultLocale);
        if (!in_array($this->default, $this->supported, true)) {
            throw new InvalidLocalizationPolicy('Default locale must be supported.');
        }
        $fallbacks = [];
        foreach ($fallbackLocales as $locale) {
            if (!is_string($locale)) {
                throw new InvalidLocalizationPolicy('Fallback locales must be strings.');
            }
            $normalized = Locale::normalize($locale);
            if (!in_array($normalized, $this->supported, true)) {
                throw new InvalidLocalizationPolicy('Fallback locale must be supported.');
            }
            $fallbacks[] = $normalized;
        }
        $this->fallbacks = array_values(array_unique($fallbacks));
        self::validateTimezone($defaultTimezone);
    }

    /** @return list<string> */
    public function supportedLocales(): array
    {
        return $this->supported;
    }
    public function defaultLocale(): string
    {
        return $this->default;
    }
    public function defaultTimezone(): string
    {
        return $this->defaultTimezone;
    }

    /** @return list<string> */
    public function fallbackChain(?string $requested): array
    {
        if ($requested === null) {
            return array_values(array_unique([$this->default, ...$this->fallbacks]));
        }
        $parts = explode('-', Locale::normalize($requested));
        $chain = [];
        while ($parts !== []) {
            $candidate = implode('-', $parts);
            if (in_array($candidate, $this->supported, true)) {
                $chain[] = $candidate;
            }
            array_pop($parts);
        }
        return array_values(array_unique([...$chain, ...$this->fallbacks, $this->default]));
    }

    public function context(?string $requested = null, ?string $timezone = null): LocalizationContext
    {
        $chain = $this->fallbackChain($requested);
        $selectedTimezone = $timezone ?? $this->defaultTimezone;
        self::validateTimezone($selectedTimezone);
        return new LocalizationContext($chain[0], $chain, $selectedTimezone);
    }

    private static function validateTimezone(string $timezone): void
    {
        if ($timezone === '' || trim($timezone) !== $timezone || strlen($timezone) > 255) {
            throw new InvalidLocalizationPolicy('Invalid timezone identifier.');
        }
        try {
            new DateTimeZone($timezone);
        } catch (Throwable $exception) {
            throw new InvalidLocalizationPolicy('Invalid timezone identifier.', 0, $exception);
        }
    }
}
