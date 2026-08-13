<?php

declare(strict_types=1);

namespace Evolve\Http\Exception;

use RuntimeException;

final class RouteNotFound extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No matching route was found.');
    }
}
