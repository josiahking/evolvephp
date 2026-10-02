<?php

declare(strict_types=1);

namespace Evolve\View;

/** @experimental */
interface ViewPathResolver
{
    public function resolve(ViewName $name): string;
}
