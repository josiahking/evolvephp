<?php

declare(strict_types=1);

namespace Evolve\Storage\Contracts;

/**
 * Vendor-neutral operations for storing and opening opaque objects.
 *
 * @experimental EvolvePHP 2 is pre-beta; this storage contract may change before stable release.
 */
interface ObjectStorage
{
    /**
     * Stores the exact concatenated byte sequence yielded by the chunks.
     * Implementations must support incremental chunk consumption; the contract must not require
     * whole-object materialization or buffering.
     * An empty iterable stores an empty object; empty-string chunks contribute no bytes.
     * A yielded non-string value is caller misuse and must be rejected with
     * \InvalidArgumentException. Caller misuse must not be translated into StorageException.
     *
     * @param iterable<string> $chunks
     *
     * @throws Exception\StorageException When a provider/backend operation fails.
     * @throws \InvalidArgumentException When an implementation receives a non-string chunk.
     */
    public function put(
        StorageKey $key,
        iterable $chunks,
    ): void;

    /**
     * Opens an object with one immediate lookup. Missing objects return null.
     * A returned readable object is owned by the caller and must be explicitly closed.
     *
     * @throws Exception\StorageException When a provider/backend operation fails.
     */
    public function open(
        StorageKey $key,
    ): ?ReadableObject;

    /**
     * Deletes an object; deleting an absent object is successful completion.
     *
     * @throws Exception\StorageException When a provider/backend operation fails.
     */
    public function delete(
        StorageKey $key,
    ): void;
}
