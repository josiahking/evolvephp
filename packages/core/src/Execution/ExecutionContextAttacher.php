<?php

declare(strict_types=1);

namespace Evolve\Core\Execution;

interface ExecutionContextAttacher
{
    public function attach(ExecutionContext $context): ExecutionContextAttachment;
}
