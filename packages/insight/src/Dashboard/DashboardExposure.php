<?php

declare(strict_types=1);

namespace Evolve\Insight\Dashboard;

final readonly class DashboardExposure
{
    private function __construct(private string $mode) {}

    public static function localDevelopment(): self
    {
        return new self('local-development');
    }

    public static function productionAuthorized(): self
    {
        return new self('production-authorized');
    }

    public static function fromMode(string $mode): self
    {
        if ($mode !== 'local-development') {
            throw new \InvalidArgumentException('Production dashboard exposure requires the explicit production-authorized factory.');
        }

        return self::localDevelopment();
    }

    public function mode(): string
    {
        return $this->mode;
    }
}
