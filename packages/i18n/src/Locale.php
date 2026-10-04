<?php

declare(strict_types=1);

namespace Evolve\I18n;

use Evolve\I18n\Exception\InvalidLocalizationPolicy;

final class Locale
{
    public static function normalize(string $locale): string
    {
        if (strlen($locale) > 64 || preg_match('/\A[A-Za-z]{2,8}(?:[-_][A-Za-z0-9]{1,8})*\z/D', $locale) !== 1) {
            throw new InvalidLocalizationPolicy('Invalid locale identifier.');
        }

        $parts = preg_split('/[-_]/', $locale);
        if ($parts === false) {
            throw new InvalidLocalizationPolicy('Invalid locale identifier.');
        }
        foreach ($parts as $index => &$part) {
            if ($index === 0) {
                $part = strtolower($part);
            } elseif (preg_match('/\A[A-Za-z]{4}\z/D', $part) === 1) {
                $part = ucfirst(strtolower($part));
            } elseif (preg_match('/\A[A-Za-z]{2}\z/D', $part) === 1) {
                $part = strtoupper($part);
            } else {
                $part = strtolower($part);
            }
        }

        return implode('-', $parts);
    }
}
