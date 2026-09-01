<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Comparator;

final class ComparatorRuntimeIdentity
{
    public const SCHEMA_VERSION = 'evolvephp.comparator.runtime-identity.v1';

    /**
     * @return array<string, mixed>
     */
    public static function capture(): array
    {
        $extensions = get_loaded_extensions();
        sort($extensions, SORT_STRING);

        return self::withFingerprint([
            'schema_version' => self::SCHEMA_VERSION,
            'runtime' => [
                'php_version' => PHP_VERSION,
                'php_binary' => PHP_BINARY,
                'php_sapi' => PHP_SAPI,
                'php_ini_loaded_file' => php_ini_loaded_file() ?: null,
            ],
            'opcache' => self::opcache(),
            'jit' => self::jit(),
            'extensions' => $extensions,
            'phalcon' => [
                'loaded' => extension_loaded('phalcon'),
                'version' => extension_loaded('phalcon') ? phpversion('phalcon') : null,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $environment
     * @return array<string, mixed>
     */
    public static function fromCapturedEnvironment(array $environment): array
    {
        $extensions = array_values(array_map('strval', $environment['extensions'] ?? []));
        sort($extensions, SORT_STRING);
        $phalconLoaded = in_array('phalcon', array_map('strtolower', $extensions), true);
        $extensionVersions = is_array($environment['extension_versions'] ?? null) ? $environment['extension_versions'] : [];
        $phalconVersion = $extensionVersions['phalcon'] ?? null;

        return self::withFingerprint([
            'schema_version' => self::SCHEMA_VERSION,
            'runtime' => [
                'php_version' => $environment['runtime']['php_version'] ?? null,
                'php_binary' => $environment['runtime']['php_binary'] ?? null,
                'php_sapi' => $environment['runtime']['php_sapi'] ?? null,
                'php_ini_loaded_file' => $environment['runtime']['php_ini_loaded_file'] ?? null,
            ],
            'opcache' => $environment['opcache'] ?? [],
            'jit' => $environment['jit'] ?? [],
            'extensions' => $extensions,
            'phalcon' => [
                'loaded' => $phalconLoaded,
                'version' => $phalconLoaded && is_string($phalconVersion) ? $phalconVersion : null,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $environment
     * @return array<string, mixed>
     */
    public static function withFingerprint(array $environment): array
    {
        self::sortRecursive($environment);
        $environment['hash'] = hash('sha256', json_encode($environment, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $environment;
    }

    /**
     * @return array{enabled: bool, configuration: array<string, string|false|null>}
     */
    private static function opcache(): array
    {
        return [
            'enabled' => filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN),
            'configuration' => [
                'opcache.enable' => ini_get('opcache.enable'),
                'opcache.enable_cli' => ini_get('opcache.enable_cli'),
                'opcache.validate_timestamps' => ini_get('opcache.validate_timestamps'),
                'opcache.memory_consumption' => ini_get('opcache.memory_consumption'),
                'opcache.interned_strings_buffer' => ini_get('opcache.interned_strings_buffer'),
                'opcache.max_accelerated_files' => ini_get('opcache.max_accelerated_files'),
            ],
        ];
    }

    /**
     * @return array{enabled: bool, configuration: array<string, string|false|null>}
     */
    private static function jit(): array
    {
        $bufferSize = ini_get('opcache.jit_buffer_size');

        return [
            'enabled' => $bufferSize !== false && $bufferSize !== '' && $bufferSize !== '0',
            'configuration' => [
                'opcache.jit' => ini_get('opcache.jit'),
                'opcache.jit_buffer_size' => $bufferSize,
            ],
        ];
    }

    /**
     * @param array<mixed> $value
     */
    private static function sortRecursive(array &$value): void
    {
        foreach ($value as &$entry) {
            if (is_array($entry)) {
                self::sortRecursive($entry);
            }
        }

        if (!array_is_list($value)) {
            ksort($value);
        }
    }
}
