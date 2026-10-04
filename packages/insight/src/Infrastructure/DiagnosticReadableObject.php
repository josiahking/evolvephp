<?php

declare(strict_types=1);

namespace Evolve\Insight\Infrastructure;

use Evolve\Storage\Contracts\ReadableObject;

final readonly class DiagnosticReadableObject implements ReadableObject
{
    private ?string $originIdentifier;

    public function __construct(private ReadableObject $reader, private DiagnosticRecorder $recorder)
    {
        $this->originIdentifier = $recorder->correlation()->identifier();
    }

    public function read(int $maxBytes): ?string
    {
        if (!$this->canRecord()) {
            return $this->reader->read($maxBytes);
        }

        return $this->recorder->run(
            'storage',
            'read',
            fn(): ?string => $this->reader->read($maxBytes),
            static fn(?string $bytes): array => ['requested_bytes' => max(0, $maxBytes), 'returned_bytes' => $bytes === null ? 0 : strlen($bytes)],
        );
    }

    public function close(): void
    {
        if (!$this->canRecord()) {
            $this->reader->close();

            return;
        }

        $this->recorder->run('storage', 'close', function (): null {
            $this->reader->close();

            return null;
        });
    }

    private function canRecord(): bool
    {
        return $this->originIdentifier !== null && $this->originIdentifier === $this->recorder->correlation()->identifier();
    }
}
