<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use Evolve\Contracts\Component\CapabilityIdentifier;
use Evolve\Contracts\Component\ComponentIdentifier;
use InvalidArgumentException;

/**
 * Raised when an explicit capability provider selection is not valid for the active graph.
 *
 * @experimental
 */
final class InvalidCapabilityProviderSelection extends ComponentGraphResolutionFailed
{
    public const REASON_INACTIVE_CONSUMER = 'inactive_consumer';

    public const REASON_CAPABILITY_NOT_REQUIRED = 'capability_not_required';

    public const REASON_UNSUPPORTED_CARDINALITY = 'unsupported_cardinality';

    public const REASON_INACTIVE_PROVIDER = 'inactive_provider';

    public const REASON_PROVIDER_DOES_NOT_PROVIDE_CAPABILITY = 'provider_does_not_provide_capability';

    public const REASON_DUPLICATE_SELECTION = 'duplicate_selection';

    /**
     * @var list<string>
     */
    private const SUPPORTED_REASONS = [
        self::REASON_INACTIVE_CONSUMER,
        self::REASON_CAPABILITY_NOT_REQUIRED,
        self::REASON_UNSUPPORTED_CARDINALITY,
        self::REASON_INACTIVE_PROVIDER,
        self::REASON_PROVIDER_DOES_NOT_PROVIDE_CAPABILITY,
        self::REASON_DUPLICATE_SELECTION,
    ];

    public function __construct(
        private ComponentIdentifier $consumer,
        private CapabilityIdentifier $capability,
        private ?ComponentIdentifier $provider,
        private string $reason,
    ) {
        if (!in_array($reason, self::SUPPORTED_REASONS, true)) {
            throw new InvalidArgumentException('Unsupported provider selection failure reason.');
        }

        parent::__construct('Capability provider selection is invalid.');
    }

    public function consumer(): ComponentIdentifier
    {
        return $this->consumer;
    }

    public function capability(): CapabilityIdentifier
    {
        return $this->capability;
    }

    public function provider(): ?ComponentIdentifier
    {
        return $this->provider;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
