<?php

declare(strict_types=1);

namespace Evolve\Storage\Local\Tests\Unit;

use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Storage\Contracts\Exception\StorageException;
use Evolve\Storage\Contracts\ObjectStorage;
use Evolve\Storage\Contracts\ReadableObject;
use Evolve\Storage\Contracts\StorageFailureCategory;
use Evolve\Storage\Contracts\StorageKey;
use Evolve\Storage\Contracts\StorageOperation;
use Evolve\Storage\Local\Internal\LocalFilesystemStorageException;
use Evolve\Storage\Local\LocalFilesystemStorage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class LocalFilesystemStorageTest extends TestCase
{
    private string $workspace;

    private string $root;

    protected function setUp(): void
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'evolvephp-storage-local-');
        self::assertNotFalse($temporaryPath);
        self::assertTrue(unlink($temporaryPath));
        self::assertTrue(mkdir($temporaryPath));

        $this->workspace = $temporaryPath;
        $this->root = $this->workspace . DIRECTORY_SEPARATOR . 'root';
        self::assertTrue(mkdir($this->root));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workspace);
    }

    public function test_adapter_is_final_has_frozen_public_surface_and_redacts_root(): void
    {
        $storage = $this->storage();
        $reflection = new ReflectionClass(LocalFilesystemStorage::class);
        $names = array_map(static fn($method): string => $method->getName(), $reflection->getMethods(ReflectionMethod::IS_PUBLIC));
        sort($names);
        $constructor = $reflection->getConstructor();

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->implementsInterface(ObjectStorage::class));
        self::assertFalse($reflection->implementsInterface(ResetParticipant::class));
        self::assertSame(['__construct', '__debugInfo', 'delete', 'open', 'put'], $names);
        self::assertSame(1, $constructor->getNumberOfParameters());
        self::assertSame('rootDirectory', $constructor->getParameters()[0]->getName());
        self::assertSame('string', (string) $constructor->getParameters()[0]->getType());
        self::assertNotEmpty($constructor->getParameters()[0]->getAttributes(\SensitiveParameter::class));
        self::assertStringNotContainsString($this->root, print_r($storage, true));
        self::assertStringNotContainsString($this->root, serialize($storage->__debugInfo()));
    }

    public function test_invalid_roots_are_rejected_as_configuration_misuse(): void
    {
        $file = $this->workspace . DIRECTORY_SEPARATOR . 'root-file';
        self::assertNotFalse(file_put_contents($file, 'not a directory'));
        $missing = $this->workspace . DIRECTORY_SEPARATOR . 'missing';
        $relative = 'relative-storage-root-' . bin2hex(random_bytes(5));

        foreach (['', "root\0with-nul", $relative, $missing, $file] as $invalidRoot) {
            try {
                new LocalFilesystemStorage($invalidRoot);
                self::fail('Invalid storage root configuration must be rejected.');
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function test_native_windows_rejects_drive_rooted_slash_paths(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            self::markTestSkipped('This regression applies to native Windows paths.');
        }

        try {
            new LocalFilesystemStorage('/');
            self::fail('A slash-rooted path is not a fully qualified Windows root.');
        } catch (InvalidArgumentException) {
        }
    }

    public function test_posix_root_is_joined_without_a_duplicate_leading_separator(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('This path-joining regression applies to POSIX roots.');
        }

        $storage = new LocalFilesystemStorage('/');
        $reflection = new ReflectionClass(LocalFilesystemStorage::class);
        $objectPath = $reflection->getMethod('objectPath')->invoke($storage, new StorageKey('root-layout'));

        self::assertStringStartsWith('/.evolvephp-storage/', $objectPath);
        self::assertFalse(str_starts_with($objectPath, '//.evolvephp-storage/'));
    }

    public function test_existing_absolute_directory_is_canonicalized_without_environment_discovery(): void
    {
        $storage = $this->storage();
        $sourcePath = (new ReflectionClass(LocalFilesystemStorage::class))->getFileName();
        self::assertNotFalse($sourcePath);
        $source = file_get_contents($sourcePath);
        self::assertNotFalse($source);

        foreach (['getcwd(', 'getenv(', '$_ENV', '$_SERVER'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }

        self::assertDirectoryExists(realpath($this->root));
        self::assertSame(['rootDirectory' => '[REDACTED]'], $storage->__debugInfo());
    }

    public function test_put_open_and_replace_round_trip_exact_binary_bytes_and_chunk_order(): void
    {
        $storage = $this->storage();
        $key = new StorageKey('roundtrip/object');
        $payload = "first\0" . "\xffsecond";

        $storage->put($key, ['first' . "\0", '', "\xffsecond"]);
        self::assertSame($payload, $this->readAll($storage->open($key)));

        $storage->put($key, ['replacement-', 'one']);
        self::assertSame('replacement-one', $this->readAll($storage->open($key)));
    }

    public function test_empty_iterable_and_empty_chunks_store_an_empty_payload(): void
    {
        $storage = $this->storage();
        $empty = new StorageKey('empty');
        $emptyChunks = new StorageKey('empty-chunks');

        $storage->put($empty, []);
        $storage->put($emptyChunks, ['', '', '']);

        self::assertSame('', $this->readAll($storage->open($empty)));
        self::assertSame('', $this->readAll($storage->open($emptyChunks)));
    }

    public function test_generator_chunks_are_published_incrementally_in_the_target_shard(): void
    {
        $storage = $this->storage();
        $key = new StorageKey('streamed');
        $shard = dirname($this->objectPath($key));
        $chunks = (static function () use ($shard): \Generator {
            yield 'first';
            $temporaryFiles = glob($shard . DIRECTORY_SEPARATOR . '.tmp-*');
            self::assertIsArray($temporaryFiles);
            self::assertCount(1, $temporaryFiles);
            $temporaryBytes = file_get_contents($temporaryFiles[0]);
            self::assertNotFalse($temporaryBytes);
            self::assertStringEndsWith('first', $temporaryBytes);
            yield 'second';
        })();

        $storage->put($key, $chunks);

        self::assertSame('firstsecond', $this->readAll($storage->open($key)));
    }

    public function test_caller_misuse_and_producer_throwable_preserve_existing_object_and_cleanup_temp_files(): void
    {
        $storage = $this->storage();
        $key = new StorageKey('preserve-existing');
        $storage->put($key, ['original']);
        $invalidChunks = (static function (): \Generator {
            yield 'partial';
            yield 123;
        })();

        try {
            call_user_func([$storage, 'put'], $key, $invalidChunks);
            self::fail('A non-string chunk must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame('original', $this->readAll($storage->open($key)));
            self::assertSame([], glob(dirname($this->objectPath($key)) . DIRECTORY_SEPARATOR . '.tmp-*'));
        }

        $producerFailure = new RuntimeException('producer failure marker');
        $throwingChunks = (static function () use ($producerFailure): \Generator {
            yield 'partial';
            throw $producerFailure;
        })();

        try {
            $storage->put($key, $throwingChunks);
            self::fail('The producer throwable must propagate.');
        } catch (RuntimeException $exception) {
            self::assertSame($producerFailure, $exception);
            self::assertSame('original', $this->readAll($storage->open($key)));
            self::assertSame([], glob(dirname($this->objectPath($key)) . DIRECTORY_SEPARATOR . '.tmp-*'));
        }
    }

    public function test_key_values_are_opaque_independent_and_only_digest_bytes_enter_paths(): void
    {
        $storage = $this->storage();
        $values = [
            '../escape',
            './folder/../object',
            'folder/object',
            'folder\\object',
            'Case',
            'case',
            'folder//object',
            ' leading and trailing ',
            " \t ",
            "binary-\xff-key",
            str_repeat('x', 1024),
        ];

        foreach ($values as $index => $value) {
            $key = new StorageKey($value);
            $storage->put($key, ['payload-' . $index]);
            self::assertSame('payload-' . $index, $this->readAll($storage->open($key)));
            $objectPath = $this->objectPath($key);
            self::assertFileExists($objectPath);
            self::assertSame(hash('sha256', $value), basename($objectPath, '.obj'));
            self::assertSame(realpath($this->root), substr(realpath(dirname($objectPath)), 0, strlen(realpath($this->root))));
        }

        self::assertNotSame($this->objectPath(new StorageKey('Case')), $this->objectPath(new StorageKey('case')));
    }

    public function test_missing_open_and_delete_do_not_create_reserved_directories(): void
    {
        $storage = $this->storage();
        $key = new StorageKey('missing');
        $reservedRoot = $this->root . DIRECTORY_SEPARATOR . '.evolvephp-storage';

        self::assertNull($storage->open($key));
        $storage->delete($key);
        $storage->delete($key);

        self::assertDirectoryDoesNotExist($reservedRoot);
    }

    public function test_exact_private_header_is_written_and_payload_follows_it(): void
    {
        $storage = $this->storage();
        $key = new StorageKey("header-\xff");
        $payload = "\0payload";
        $storage->put($key, [$payload]);
        $bytes = file_get_contents($this->objectPath($key));

        self::assertNotFalse($bytes);
        self::assertSame('EVPHPST1' . pack('n', strlen($key->value())) . $key->value() . $payload, $bytes);
    }

    public function test_malformed_and_mismatched_headers_fail_open_put_and_delete_safely(): void
    {
        $storage = $this->storage();
        $malformed = new StorageKey('malformed');
        $this->writeObjectFile($malformed, 'not-a-header');
        $this->assertStorageFailure(fn() => $storage->open($malformed), StorageOperation::Open, [$malformed->value(), $this->root]);
        $this->assertStorageFailure(fn() => $storage->put($malformed, ['replacement']), StorageOperation::Put, [$malformed->value(), $this->root]);
        $this->assertStorageFailure(fn() => $storage->delete($malformed), StorageOperation::Delete, [$malformed->value(), $this->root]);

        $requested = new StorageKey('requested-key');
        $this->writeObjectFile($requested, 'EVPHPST1' . pack('n', 6) . 'other!' . 'payload');
        $this->assertStorageFailure(fn() => $storage->open($requested), StorageOperation::Open, [$requested->value(), $this->root]);
        $this->assertStorageFailure(fn() => $storage->put($requested, ['new']), StorageOperation::Put, [$requested->value(), $this->root]);
        $this->assertStorageFailure(fn() => $storage->delete($requested), StorageOperation::Delete, [$requested->value(), $this->root]);
    }

    public function test_internal_path_conflicts_and_non_regular_targets_are_storage_failures(): void
    {
        $reserved = $this->root . DIRECTORY_SEPARATOR . '.evolvephp-storage';
        self::assertNotFalse(file_put_contents($reserved, 'conflict'));
        $storage = $this->storage();

        $this->assertStorageFailure(fn() => $storage->put(new StorageKey('internal-conflict'), ['x']), StorageOperation::Put);
        self::assertStorageFailure(fn() => $storage->open(new StorageKey('internal-conflict')), StorageOperation::Open);

        self::assertTrue(unlink($reserved));
        $key = new StorageKey('directory-target');
        $this->createObjectShard($key);
        self::assertTrue(mkdir($this->objectPath($key)));
        $this->assertStorageFailure(fn() => $storage->open($key), StorageOperation::Open);
        $this->assertStorageFailure(fn() => $storage->delete($key), StorageOperation::Delete);
        $this->assertStorageFailure(fn() => $storage->put($key, ['x']), StorageOperation::Put);
    }

    public function test_symbolic_link_target_is_rejected_when_symlinks_are_available(): void
    {
        $storage = $this->storage();
        $key = new StorageKey('linked-target');
        $this->createObjectShard($key);
        $outside = $this->workspace . DIRECTORY_SEPARATOR . 'outside';
        self::assertNotFalse(file_put_contents($outside, 'outside data'));
        $objectPath = $this->objectPath($key);

        if (!@symlink($outside, $objectPath)) {
            self::markTestSkipped('The current platform does not allow creating a symbolic link.');
        }

        $this->assertStorageFailure(fn() => $storage->open($key), StorageOperation::Open, [$this->root, $key->value()]);
        $this->assertStorageFailure(fn() => $storage->delete($key), StorageOperation::Delete, [$this->root, $key->value()]);
    }

    public function test_internal_storage_failure_exposes_only_bounded_operation_and_category(): void
    {
        $failure = new LocalFilesystemStorageException(StorageOperation::Put);
        $reflection = new ReflectionClass(LocalFilesystemStorageException::class);

        self::assertTrue($reflection->isFinal());
        self::assertStringContainsString('@internal', (string) $reflection->getDocComment());
        self::assertSame(StorageOperation::Put, $failure->operation());
        self::assertSame(StorageFailureCategory::Unknown, $failure->category());
        self::assertSame('Local filesystem storage operation failed.', $failure->getMessage());
        self::assertNull($failure->getPrevious());
    }

    private function storage(): LocalFilesystemStorage
    {
        return new LocalFilesystemStorage($this->root);
    }

    private function readAll(?ReadableObject $object): string
    {
        self::assertNotNull($object);
        $payload = '';

        try {
            while (($chunk = $object->read(3)) !== null) {
                $payload .= $chunk;
            }
        } finally {
            $object->close();
        }

        return $payload;
    }

    private function objectPath(StorageKey $key): string
    {
        $digest = hash('sha256', $key->value());

        return $this->root
            . DIRECTORY_SEPARATOR . '.evolvephp-storage'
            . DIRECTORY_SEPARATOR . 'v1'
            . DIRECTORY_SEPARATOR . 'objects'
            . DIRECTORY_SEPARATOR . substr($digest, 0, 2)
            . DIRECTORY_SEPARATOR . substr($digest, 2, 2)
            . DIRECTORY_SEPARATOR . $digest . '.obj';
    }

    private function createObjectShard(StorageKey $key): void
    {
        $directory = dirname($this->objectPath($key));
        self::assertTrue(mkdir($directory, 0777, true) || is_dir($directory));
    }

    private function writeObjectFile(StorageKey $key, string $contents): void
    {
        $this->createObjectShard($key);
        self::assertNotFalse(file_put_contents($this->objectPath($key), $contents));
    }

    /** @param list<string> $forbidden */
    private function assertStorageFailure(callable $operation, StorageOperation $expectedOperation, array $forbidden = []): void
    {
        try {
            $operation();
            self::fail('The invalid filesystem object must fail through StorageException.');
        } catch (StorageException $exception) {
            self::assertSame($expectedOperation, $exception->operation());
            self::assertSame(StorageFailureCategory::Unknown, $exception->category());
            self::assertSame('Local filesystem storage operation failed.', $exception->getMessage());
            self::assertNull($exception->getPrevious());

            foreach ($forbidden as $secret) {
                self::assertStringNotContainsString($secret, $exception->getMessage());
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            if (file_exists($directory) || is_link($directory)) {
                @unlink($directory);
            }

            return;
        }

        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            $path = $entry->getPathname();

            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
