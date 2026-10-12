<?php

declare(strict_types=1);

namespace Evolve\Mcp\Contribution\Exception;

use Evolve\Contracts\Component\ComponentIdentifier;
use RuntimeException;
use Throwable;

/** Safe attribution of a failed component contribution.
 * @experimental
 */
final class McpContributionFailed extends RuntimeException
{
    private function __construct(
        private ComponentIdentifier $component,
        Throwable $previous,
    ) {
        parent::__construct('MCP component contribution failed.', 0, $previous);
    }

    public static function forComponent(ComponentIdentifier $component, Throwable $previous): self
    {
        return new self($component, $previous);
    }

    public function component(): ComponentIdentifier
    {
        return $this->component;
    }
}
