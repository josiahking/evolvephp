<?php

declare(strict_types=1);

namespace Evolve\Storage\Contracts\Tests\Unit;

use Evolve\Contracts\Exception\EvolveException;
use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Storage\Contracts\Exception\StorageException;
use Evolve\Storage\Contracts\ObjectStorage;
use Evolve\Storage\Contracts\ReadableObject;
use Evolve\Storage\Contracts\StorageFailureCategory;
use Evolve\Storage\Contracts\StorageKey;
use Evolve\Storage\Contracts\StorageOperation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class StorageContractsTest extends TestCase
{
    public function test_object_storage_has_exact_streaming_oriented_contract_surface(): void
    {
        $storage = new ReflectionClass(ObjectStorage::class);
        $methods = $storage->getMethods();
        $names = array_map(static fn($method): string => $method->getName(), $methods);
        sort($names);

        self::assertTrue($storage->isInterface());
        self::assertFalse($storage->implementsInterface(ResetParticipant::class));
        self::assertSame(['delete', 'open', 'put'], $names);

        $put = $storage->getMethod('put');
        self::assertSame('void', (string) $put->getReturnType());
        self::assertSame([StorageKey::class, 'iterable'], array_map(
            static fn($parameter): string => (string) $parameter->getType(),
            $put->getParameters(),
        ));
        self::assertSame(['key', 'chunks'], array_map(
            static fn($parameter): string => $parameter->getName(),
            $put->getParameters(),
        ));
        self::assertStringContainsString('@param iterable<string> $chunks', $put->getDocComment());
        self::assertStringContainsString('@throws Exception\\StorageException', $put->getDocComment());
        self::assertStringContainsString('@throws \\InvalidArgumentException', $put->getDocComment());
        self::assertMatchesRegularExpression(
            '/must support incremental chunk consumption/i',
            $put->getDocComment(),
        );
        self::assertMatchesRegularExpression(
            '/must not require[\s*]+(?:whole-object|complete-object)[\s*]+(?:materiali[sz]ation|buffering)/i',
            $put->getDocComment(),
        );
        self::assertMatchesRegularExpression(
            '/a yielded non-string value is caller misuse and must be rejected with/i',
            $put->getDocComment(),
        );
        self::assertMatchesRegularExpression(
            '/caller misuse must not be translated into StorageException/i',
            $put->getDocComment(),
        );

        $open = $storage->getMethod('open');
        self::assertSame('?' . ReadableObject::class, (string) $open->getReturnType());
        self::assertSame([StorageKey::class], array_map(
            static fn($parameter): string => (string) $parameter->getType(),
            $open->getParameters(),
        ));
        self::assertSame(['key'], array_map(
            static fn($parameter): string => $parameter->getName(),
            $open->getParameters(),
        ));
        self::assertStringContainsString('@throws Exception\\StorageException', $open->getDocComment());

        $delete = $storage->getMethod('delete');
        self::assertSame('void', (string) $delete->getReturnType());
        self::assertSame([StorageKey::class], array_map(
            static fn($parameter): string => (string) $parameter->getType(),
            $delete->getParameters(),
        ));
        self::assertSame(['key'], array_map(
            static fn($parameter): string => $parameter->getName(),
            $delete->getParameters(),
        ));
        self::assertStringContainsString('@throws Exception\\StorageException', $delete->getDocComment());
    }

    public function test_readable_object_has_bounded_read_and_explicit_close_only(): void
    {
        $readable = new ReflectionClass(ReadableObject::class);
        $names = array_map(static fn($method): string => $method->getName(), $readable->getMethods());
        sort($names);

        self::assertTrue($readable->isInterface());
        self::assertFalse($readable->implementsInterface(ResetParticipant::class));
        self::assertSame(['close', 'read'], $names);
        self::assertSame('?string', (string) $readable->getMethod('read')->getReturnType());
        self::assertSame(['int'], array_map(
            static fn($parameter): string => (string) $parameter->getType(),
            $readable->getMethod('read')->getParameters(),
        ));
        self::assertSame(['maxBytes'], array_map(
            static fn($parameter): string => $parameter->getName(),
            $readable->getMethod('read')->getParameters(),
        ));
        self::assertStringContainsString('@throws Exception\\StorageException', $readable->getMethod('read')->getDocComment());
        self::assertSame('void', (string) $readable->getMethod('close')->getReturnType());
        self::assertStringContainsString('@throws Exception\\StorageException', $readable->getMethod('close')->getDocComment());
    }

    public function test_storage_operations_and_failure_categories_are_frozen(): void
    {
        self::assertSame([
            'Put' => 'put',
            'Open' => 'open',
            'Read' => 'read',
            'Delete' => 'delete',
            'Close' => 'close',
        ], $this->backedValues(StorageOperation::class));
        self::assertSame([
            'Authentication' => 'authentication',
            'Authorization' => 'authorization',
            'Timeout' => 'timeout',
            'Unavailable' => 'unavailable',
            'Capacity' => 'capacity',
            'Transport' => 'transport',
            'Unknown' => 'unknown',
        ], $this->backedValues(StorageFailureCategory::class));
    }

    public function test_storage_exception_is_a_narrow_evolve_exception_boundary(): void
    {
        $exception = new ReflectionClass(StorageException::class);
        $methodNames = array_map(
            static fn($method): string => $method->getName(),
            array_filter(
                $exception->getMethods(),
                static fn($method): bool => $method->getDeclaringClass()->getName() === StorageException::class,
            ),
        );
        sort($methodNames);

        self::assertTrue($exception->isInterface());
        self::assertTrue($exception->implementsInterface(EvolveException::class));
        self::assertContains(EvolveException::class, $exception->getInterfaceNames());
        self::assertSame(['category', 'operation'], $methodNames);
        self::assertSame(StorageOperation::class, (string) $exception->getMethod('operation')->getReturnType());
        self::assertSame(StorageFailureCategory::class, (string) $exception->getMethod('category')->getReturnType());
    }

    /** @return array<string, string> */
    private function backedValues(string $enum): array
    {
        $values = [];

        foreach ($enum::cases() as $case) {
            $values[$case->name] = $case->value;
        }

        return $values;
    }
}
