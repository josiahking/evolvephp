<?php

declare(strict_types=1);

namespace Evolve\Ai;

interface ModelProvider
{
    public function invoke(ModelRequest $request, InvocationOptions $options): ModelResponse;
}
