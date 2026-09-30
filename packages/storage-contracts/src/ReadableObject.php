<?php

declare(strict_types=1);

namespace Evolve\Storage\Contracts;

/**
 * Caller-owned sequential object reader with bounded reads and explicit closure.
 *
 * @experimental EvolvePHP 2 is pre-beta; this storage contract may change before stable release.
 */
interface ReadableObject
{
    /**
     * Reads at most the requested number of bytes.
     * maxBytes must be positive; implementations reject zero or negative values with
     * InvalidArgumentException. While bytes remain, the result must be non-empty and no longer
     * than maxBytes. Null indicates end-of-object, including on the first read of an empty object.
     *
     * @throws Exception\StorageException When a provider/backend operation fails.
     * @throws \InvalidArgumentException When maxBytes is not positive.
     */
    public function read(
        int $maxBytes,
    ): ?string;

    /**
     * Releases provider/read resources. Repeated calls after successful closure are harmless.
     *
     * @throws Exception\StorageException When a provider/backend operation fails.
     */
    public function close(): void;
}
