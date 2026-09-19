<?php

declare(strict_types=1);

namespace Evolve\Core\Execution;

use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final readonly class ExecutionContextValues
{
    private const int MAX_LOCALE_LENGTH = 64;
    private const int MAX_TIMEZONE_LENGTH = 255;

    public function __construct(
        private ?string $locale = null,
        private ?string $timezone = null,
    ) {
        $this->validateLocale($locale);
        $this->validateTimezone($timezone);
    }

    public function locale(): ?string
    {
        return $this->locale;
    }

    public function timezone(): ?string
    {
        return $this->timezone;
    }

    private function validateLocale(?string $locale): void
    {
        if ($locale === null) {
            return;
        }

        if ($locale === '') {
            throw new InvalidArgumentException('Execution locale must not be empty.');
        }

        if (trim($locale) !== $locale) {
            throw new InvalidArgumentException('Execution locale must not contain leading or trailing whitespace.');
        }

        if (strlen($locale) > self::MAX_LOCALE_LENGTH) {
            throw new InvalidArgumentException('Execution locale must not exceed 64 bytes.');
        }

        if (preg_match('/\A[A-Za-z]{2,8}(?:[-_][A-Za-z0-9]{1,8})*\z/', $locale) !== 1) {
            throw new InvalidArgumentException('Execution locale must be a structurally valid locale identifier.');
        }
    }

    private function validateTimezone(?string $timezone): void
    {
        if ($timezone === null) {
            return;
        }

        if ($timezone === '') {
            throw new InvalidArgumentException('Execution timezone must not be empty.');
        }

        if (trim($timezone) !== $timezone) {
            throw new InvalidArgumentException('Execution timezone must not contain leading or trailing whitespace.');
        }

        if (strlen($timezone) > self::MAX_TIMEZONE_LENGTH) {
            throw new InvalidArgumentException('Execution timezone must not exceed 255 bytes.');
        }

        try {
            new DateTimeZone($timezone);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Execution timezone must be a valid PHP timezone identifier.', 0, $exception);
        }
    }
}
