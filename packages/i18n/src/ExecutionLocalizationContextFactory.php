<?php

declare(strict_types=1);

namespace Evolve\I18n;

use Evolve\Core\Execution\ExecutionContext;

final readonly class ExecutionLocalizationContextFactory
{
    public function __construct(private LocalizationPolicy $policy) {}

    public function fromExecution(ExecutionContext $execution): LocalizationContext
    {
        $locale = $execution->locale();
        $timezone = $execution->timezone();
        return $this->policy->context($locale, $timezone);
    }
}
