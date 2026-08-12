<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use RuntimeException;

final class CommandNotFound extends RuntimeException
{
    public static function forName(string $name): self
    {
        return new self('Command "' . $name . '" was not found.');
    }
}
