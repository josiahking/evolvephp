<?php

declare(strict_types=1);

namespace Evolve\Storage\Local\Tests\Unit\Internal;

use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Storage\Contracts\Exception\StorageException;
use Evolve\Storage\Contracts\StorageFailureCategory;
use Evolve\Storage\Contracts\StorageOperation;
use Evolve\Storage\Local\Internal\LocalFilesystemReadableObject;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class LocalFilesystemReadableObjectTest extends TestCase
{
    /** @var resource|null */
    private $stream;

    protected function tearDown(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function test_reader_returns_bounded_nonempty_chunks_and_null_at_eof(): void
    {
        $reader = $this->reader('abcdefgh');

        self::assertSame('abc', $reader->read(3));
        self::assertSame('def', $reader->read(3));
        self::assertSame('gh', $reader->read(3));
        self::assertNull($reader->read(3));
        $reader->close();
    }

    public function test_empty_payload_returns_null_immediately(): void
    {
        $reader = $this->reader('');

        self::assertNull($reader->read(10));
        $reader->close();
    }

    public function test_non_positive_read_size_is_rejected(): void
    {
        $reader = $this->reader('data');
        $this->expectException(\InvalidArgumentException::class);

        $reader->read(0);
    }

    public function test_read_after_close_throws_logic_exception(): void
    {
        $reader = $this->reader('data');
        $reader->close();

        $this->expectException(LogicException::class);
        $reader->read(1);
    }

    public function test_close_is_idempotent_and_reader_has_no_reset_or_resource_getter(): void
    {
        $reader = $this->reader('data');
        $reader->close();
        $reader->close();
        $reflection = new ReflectionClass(LocalFilesystemReadableObject::class);
        $names = array_map(static fn($method): string => $method->getName(), $reflection->getMethods(ReflectionMethod::IS_PUBLIC));
        sort($names);

        self::assertTrue($reflection->isFinal());
        self::assertStringContainsString('@internal', (string) $reflection->getDocComment());
        self::assertTrue($reflection->implementsInterface(\Evolve\Storage\Contracts\ReadableObject::class));
        self::assertFalse($reflection->implementsInterface(ResetParticipant::class));
        self::assertFalse($reflection->hasMethod('__destruct'));
        self::assertSame(['__construct', '__debugInfo', 'close', 'read'], $names);
    }

    public function test_closed_underlying_stream_is_reported_as_a_read_failure(): void
    {
        $reader = $this->reader('data');
        fclose($this->stream);
        $this->stream = null;

        try {
            $reader->read(2);
            self::fail('A closed underlying stream must fail as a storage read.');
        } catch (StorageException $exception) {
            self::assertSame(StorageOperation::Read, $exception->operation());
            self::assertSame(StorageFailureCategory::Unknown, $exception->category());
            self::assertSame('Local filesystem storage operation failed.', $exception->getMessage());
        }
    }

    public function test_close_of_unusable_resource_reports_close_operation_without_reuse(): void
    {
        $reader = $this->reader('data');
        fclose($this->stream);
        $this->stream = null;

        try {
            $reader->close();
            self::fail('An unusable stream must report a close failure.');
        } catch (StorageException $exception) {
            self::assertSame(StorageOperation::Close, $exception->operation());
            self::assertSame(StorageFailureCategory::Unknown, $exception->category());
        }

        $reader->close();
        $this->expectException(LogicException::class);
        $reader->read(1);
    }

    private function reader(string $payload): LocalFilesystemReadableObject
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        self::assertNotFalse(fwrite($stream, $payload));
        rewind($stream);
        $this->stream = $stream;

        return new LocalFilesystemReadableObject($stream);
    }
}
