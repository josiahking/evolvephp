<?php

declare(strict_types=1);

namespace Evolve\Mcp\Contribution;

use Closure;
use Evolve\Contracts\Component\ComponentGraphDeclaration;

/** Explicit binding to an exact Core-resolved declaration object.
 * @experimental
 */
final class McpComponentContribution
{
    /** @var Closure(McpCapabilityRegistrar): void */
    private Closure $callback;

    /** @param callable(McpCapabilityRegistrar): void $callback */
    public function __construct(
        private ComponentGraphDeclaration $declaration,
        callable $callback,
    ) {
        $this->callback = Closure::fromCallable($callback);
    }

    public function declaration(): ComponentGraphDeclaration
    {
        return $this->declaration;
    }

    public function contribute(McpCapabilityRegistrar $registrar): void
    {
        ($this->callback)($registrar);
    }
}
