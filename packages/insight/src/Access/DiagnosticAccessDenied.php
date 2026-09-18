<?php

declare(strict_types=1);

namespace Evolve\Insight\Access;

final class DiagnosticAccessDenied extends \RuntimeException
{
    public static function list(): self
    {
        return new self('Insight diagnostic list access denied.');
    }

    public static function detail(): self
    {
        return new self('Insight diagnostic detail access denied.');
    }
}
