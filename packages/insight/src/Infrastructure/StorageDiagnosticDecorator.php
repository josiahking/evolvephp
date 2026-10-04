<?php

declare(strict_types=1);

namespace Evolve\Insight\Infrastructure;

use Evolve\Storage\Contracts\ObjectStorage;
use Evolve\Storage\Contracts\ReadableObject;
use Evolve\Storage\Contracts\StorageKey;

final readonly class StorageDiagnosticDecorator implements ObjectStorage
{
    public function __construct(private ObjectStorage $storage, private DiagnosticRecorder $recorder) {}

    public function put(StorageKey $key, iterable $chunks): void
    {
        $this->recorder->run('storage', 'put', function () use ($key, $chunks): null {
            $this->storage->put($key, $chunks);

            return null;
        });
    }

    public function open(StorageKey $key): ?ReadableObject
    {
        $reader = $this->recorder->run(
            'storage',
            'open',
            fn(): ?ReadableObject => $this->storage->open($key),
            static fn(?ReadableObject $result): array => ['present' => $result !== null],
        );

        return $reader === null ? null : new DiagnosticReadableObject($reader, $this->recorder);
    }

    public function delete(StorageKey $key): void
    {
        $this->recorder->run('storage', 'delete', function () use ($key): null {
            $this->storage->delete($key);

            return null;
        });
    }
}
