<?php

declare(strict_types=1);

namespace Evolve\Webhooks;

final readonly class DeliveryResult
{
    public function __construct(public string $outcome, public ?int $status) {}
}
