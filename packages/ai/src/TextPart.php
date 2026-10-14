<?php

declare(strict_types=1);

namespace Evolve\Ai;

use InvalidArgumentException;

final readonly class TextPart
{
    public function __construct(public string $text)
    {
        if (trim($text) === '' || strlen($text) > 65536 || preg_match('//u', $text) !== 1) {
            throw new InvalidArgumentException('Text content must not be blank.');
        }
    }
}
