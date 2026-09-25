<?php

declare(strict_types=1);

namespace Evolve\Observe\Export;

use InvalidArgumentException;

final readonly class BatchExportConfiguration
{
    public function __construct(
        private int $maxQueueSize = 2048,
        private int $maxExportBatchSize = 512,
        private int $scheduledDelayMillis = 5000,
        private bool $autoFlush = true,
    ) {
        if ($this->maxQueueSize <= 0) {
            throw new InvalidArgumentException('Maximum export queue size must be greater than zero.');
        }

        if ($this->maxExportBatchSize <= 0) {
            throw new InvalidArgumentException('Maximum export batch size must be greater than zero.');
        }

        if ($this->scheduledDelayMillis <= 0) {
            throw new InvalidArgumentException('Scheduled export delay must be greater than zero.');
        }

        if ($this->maxExportBatchSize > $this->maxQueueSize) {
            throw new InvalidArgumentException('Maximum export batch size must not exceed maximum queue size.');
        }
    }

    public function maxQueueSize(): int
    {
        return $this->maxQueueSize;
    }

    public function maxExportBatchSize(): int
    {
        return $this->maxExportBatchSize;
    }

    public function scheduledDelayMillis(): int
    {
        return $this->scheduledDelayMillis;
    }

    public function autoFlush(): bool
    {
        return $this->autoFlush;
    }
}
