<?php

declare(strict_types=1);

namespace Evolve\View\Native;

/** Trust marker for caller-reviewed HTML; it does not sanitize input. @experimental */
final readonly class TrustedHtml
{
    public function __construct(public string $html) {}
}
