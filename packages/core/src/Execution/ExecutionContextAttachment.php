<?php

declare(strict_types=1);

namespace Evolve\Core\Execution;

interface ExecutionContextAttachment
{
    public function detach(): void;
}
