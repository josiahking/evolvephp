<?php

declare(strict_types=1);

namespace Evolve\Ai;

use RuntimeException;

final class ModelInvocationException extends RuntimeException
{
    public function __construct(public readonly ModelFailureCategory $category)
    {
        parent::__construct('Model invocation failed: ' . $category->value . '.');
    }
}
