<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Configuration;

use Evolve\Contracts\Configuration\Configuration;
use Evolve\Contracts\Exception\ConfigurationException;
use Evolve\Core\Configuration\ArrayConfiguration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

final class ArrayConfigurationTest extends TestCase
{
    public function test_array_configuration_is_final_read_only_configuration(): void
    {
        self::assertTrue(class_exists(ArrayConfiguration::class), ArrayConfiguration::class . ' should exist.');
        self::assertTrue(interface_exists(Configuration::class), Configuration::class . ' should exist.');

        $configuration = new ReflectionClass(ArrayConfiguration::class);

        self::assertTrue($configuration->isFinal());
        self::assertTrue($configuration->implementsInterface(Configuration::class));

        foreach (['set', 'replace', 'merge', 'remove', 'clear', 'freeze', 'isFrozen', 'load', 'environment', 'schema'] as $method) {
            self::assertFalse($configuration->hasMethod($method), ArrayConfiguration::class . ' must not expose ' . $method . '().');
        }
    }

    public function test_empty_configuration_is_valid(): void
    {
        $configuration = $this->newConfiguration();

        self::assertSame([], $configuration->all());
        self::assertFalse($configuration->has('missing'));
        self::assertSame('fallback', $configuration->get('missing', 'fallback'));
    }

    public function test_top_level_and_nested_dot_paths_are_readable(): void
    {
        $configuration = $this->newConfiguration([
            'app' => 'evolve',
            'database' => [
                'host' => 'localhost',
                'options' => [
                    'timeout' => 30,
                ],
            ],
            'mail' => [
                'transport' => 'smtp',
            ],
        ]);

        self::assertTrue($configuration->has('app'));
        self::assertTrue($configuration->has('database.host'));
        self::assertSame('evolve', $configuration->get('app'));
        self::assertSame('localhost', $configuration->get('database.host'));
        self::assertSame(30, $configuration->require('database.options.timeout'));
        self::assertSame('smtp', $configuration->require('mail.transport'));
    }

    public function test_null_is_present_and_missing_values_use_default_or_exception(): void
    {
        $configuration = $this->newConfiguration([
            'feature' => [
                'value' => null,
            ],
        ]);

        self::assertTrue($configuration->has('feature.value'));
        self::assertFalse($configuration->has('missing.value'));
        self::assertNull($configuration->get('feature.value', 'fallback'));
        self::assertSame('fallback', $configuration->get('missing.value', 'fallback'));
        self::assertNull($configuration->require('feature.value'));

        $this->assertFailsThroughConfigurationException(static function () use ($configuration): void {
            $configuration->require('missing.value');
        });
    }

    public function test_all_returns_complete_data_without_mutation_leakage(): void
    {
        $configuration = $this->newConfiguration([
            'database' => [
                'host' => 'localhost',
            ],
        ]);

        $exported = $configuration->all();
        $exported['database']['host'] = 'changed';

        self::assertSame(['database' => ['host' => 'localhost']], $configuration->all());
        self::assertSame('localhost', $configuration->get('database.host'));
    }

    public function test_nested_associative_maps_and_lists_remain_valid_data(): void
    {
        $configuration = $this->newConfiguration([
            'database' => [
                'options' => [
                    'timeout' => 30,
                ],
            ],
            'servers' => [
                [
                    'host' => 'one',
                ],
                [
                    'host' => 'two',
                ],
            ],
            'callable_like_data' => [
                'strlen',
                ['ExampleClass', 'method'],
            ],
        ]);

        self::assertSame(['timeout' => 30], $configuration->require('database.options'));
        self::assertSame([['host' => 'one'], ['host' => 'two']], $configuration->require('servers'));
        self::assertSame(['strlen', ['ExampleClass', 'method']], $configuration->require('callable_like_data'));
    }

    public function test_object_values_are_rejected(): void
    {
        $this->assertFailsThroughConfigurationException(static function (): void {
            new ArrayConfiguration(['invalid' => new \stdClass()]);
        });
    }

    public function test_resource_values_are_rejected(): void
    {
        $resource = fopen('php://memory', 'r');

        self::assertIsResource($resource);

        try {
            $this->assertFailsThroughConfigurationException(static function () use ($resource): void {
                new ArrayConfiguration(['invalid' => $resource]);
            });
        } finally {
            fclose($resource);
        }
    }

    public function test_non_empty_root_list_is_rejected(): void
    {
        $this->assertFailsThroughConfigurationException(static function (): void {
            new ArrayConfiguration(['one', 'two']);
        });
    }

    public function test_mixed_associative_and_integer_key_maps_are_rejected(): void
    {
        $this->assertFailsThroughConfigurationException(static function (): void {
            new ArrayConfiguration(['valid' => true, 0 => 'ambiguous']);
        });
    }

    public function test_empty_associative_keys_are_rejected(): void
    {
        $this->assertFailsThroughConfigurationException(static function (): void {
            new ArrayConfiguration(['' => 'invalid']);
        });
    }

    public function test_associative_keys_containing_dots_are_rejected(): void
    {
        $this->assertFailsThroughConfigurationException(static function (): void {
            new ArrayConfiguration(['database.host' => 'invalid']);
        });
    }

    public function test_malformed_lookup_paths_are_rejected(): void
    {
        $configuration = $this->newConfiguration(['database' => ['host' => 'localhost']]);

        foreach (['', '.database', 'database.', 'database..host', '0', 'database.0'] as $path) {
            $this->assertFailsThroughConfigurationException(static function () use ($configuration, $path): void {
                $configuration->has($path);
            });

            $this->assertFailsThroughConfigurationException(static function () use ($configuration, $path): void {
                $configuration->get($path);
            });

            $this->assertFailsThroughConfigurationException(static function () use ($configuration, $path): void {
                $configuration->require($path);
            });
        }
    }

    public function test_list_indexes_are_not_public_dot_path_segments(): void
    {
        $configuration = $this->newConfiguration([
            'servers' => [
                ['host' => 'one'],
            ],
        ]);

        $this->assertFailsThroughConfigurationException(static function () use ($configuration): void {
            $configuration->get('servers.0.host');
        });
    }

    /**
     * @param array<string, mixed> $values
     */
    private function newConfiguration(array $values = []): ArrayConfiguration
    {
        self::assertTrue(class_exists(ArrayConfiguration::class), ArrayConfiguration::class . ' should exist.');

        return new ArrayConfiguration($values);
    }

    /**
     * @param callable(): mixed $operation
     */
    private function assertFailsThroughConfigurationException(callable $operation): void
    {
        self::assertTrue(interface_exists(ConfigurationException::class), ConfigurationException::class . ' should exist.');

        try {
            $operation();
            self::fail('Invalid configuration operation should throw a configuration exception.');
        } catch (Throwable $exception) {
            self::assertInstanceOf(ConfigurationException::class, $exception);
        }
    }
}
