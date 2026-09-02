<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Support;

use Composer\InstalledVersions;
use DateTimeImmutable;
use DateTimeZone;

final class BenchmarkEnvironment
{
    public const SCHEMA_VERSION = 'evolvephp.benchmark.environment.v1';

    /**
     * @return array<string, mixed>
     */
    public static function capture(string $repositoryRoot): array
    {
        $repositoryRoot = rtrim($repositoryRoot, DIRECTORY_SEPARATOR);
        $extensions = get_loaded_extensions();
        sort($extensions, SORT_STRING);

        $environment = [
            'schema_version' => self::SCHEMA_VERSION,
            'captured_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'source' => [
                'git_sha' => self::command($repositoryRoot, ['git', 'rev-parse', 'HEAD']) ?? 'unavailable',
                'dirty' => self::command($repositoryRoot, ['git', 'status', '--short']) !== '',
            ],
            'runtime' => [
                'php_version' => PHP_VERSION,
                'php_sapi' => PHP_SAPI,
                'php_binary' => PHP_BINARY,
                'php_ini_loaded_file' => php_ini_loaded_file() ?: null,
                'memory_limit' => ini_get('memory_limit') ?: null,
            ],
            'platform' => [
                'os' => PHP_OS_FAMILY,
                'php_os' => PHP_OS,
                'php_uname' => php_uname(),
                'cpu_model' => self::cpuModel(),
                'logical_cpu_count' => self::logicalCpuCount(),
                'memory_total_bytes' => self::totalMemoryBytes(),
            ],
            'composer' => [
                'version' => self::composerVersion($repositoryRoot),
            ],
            'phpbench' => [
                'version' => self::installedVersion('phpbench/phpbench'),
            ],
            'opcache' => self::opcache(),
            'jit' => self::jit(),
            'extensions' => $extensions,
            'extension_versions' => self::extensionVersions($extensions),
            'lock' => self::lockState($repositoryRoot),
        ];

        $environment['fingerprint'] = EnvironmentFingerprint::fromEnvironment($environment);
        self::sortRecursive($environment);

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
     * @param list<string> $extensions
     * @return array<string, string|null>
     */
    private static function extensionVersions(array $extensions): array
    {
        $versions = [];

        foreach (['phalcon'] as $extension) {
            if (!in_array($extension, array_map('strtolower', $extensions), true)) {
                continue;
            }

            $version = phpversion($extension);
            $versions[$extension] = is_string($version) ? $version : null;
        }

        return $versions;
    }

    /**
     * @return array{path: string, exists: bool, hash: string|null}
     */
    private static function lockState(string $repositoryRoot): array
    {
        $path = $repositoryRoot . DIRECTORY_SEPARATOR . 'benchmarks' . DIRECTORY_SEPARATOR . 'composer.lock';

        return [
            'path' => 'benchmarks/composer.lock',
            'exists' => is_file($path),
            'hash' => is_file($path) ? hash_file('sha256', $path) : null,
        ];
    }

    private static function installedVersion(string $package): ?string
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled($package)) {
            return null;
        }

        return InstalledVersions::getPrettyVersion($package) ?? InstalledVersions::getVersion($package);
    }

    private static function composerVersion(string $repositoryRoot): ?string
    {
        $binary = getenv('COMPOSER_BINARY') ?: null;

        if ($binary !== null) {
            return self::command($repositoryRoot, [PHP_BINARY, $binary, '--version']);
        }

        $windowsComposer = 'D:\\tools\\composer84\\composer.phar';

        if (is_file($windowsComposer)) {
            return self::command($repositoryRoot, [PHP_BINARY, $windowsComposer, '--version']);
        }

        return self::command($repositoryRoot, ['composer', '--version']);
    }

    private static function cpuModel(): ?string
    {
        $processor = getenv('PROCESSOR_IDENTIFIER');

        if (is_string($processor) && $processor !== '') {
            return $processor;
        }

        if (is_readable('/proc/cpuinfo')) {
            $cpuinfo = file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES);

            foreach ($cpuinfo === false ? [] : $cpuinfo as $line) {
                if (str_starts_with($line, 'model name')) {
                    return trim((string) preg_replace('/^model name\s*:\s*/', '', $line));
                }
            }
        }

        return null;
    }

    private static function logicalCpuCount(): ?int
    {
        $windowsCount = getenv('NUMBER_OF_PROCESSORS');

        if (is_string($windowsCount) && ctype_digit($windowsCount)) {
            return (int) $windowsCount;
        }

        $linuxCount = self::command(getcwd() ?: __DIR__, ['getconf', '_NPROCESSORS_ONLN']);

        return $linuxCount !== null && ctype_digit($linuxCount) ? (int) $linuxCount : null;
    }

    private static function totalMemoryBytes(): ?int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $memory = self::command(getcwd() ?: __DIR__, ['wmic', 'ComputerSystem', 'get', 'TotalPhysicalMemory', '/value']);

            if (is_string($memory) && preg_match('/TotalPhysicalMemory=(\d+)/', $memory, $match) === 1) {
                return (int) $match[1];
            }
        }

        if (is_readable('/proc/meminfo')) {
            $meminfo = file('/proc/meminfo', FILE_IGNORE_NEW_LINES);

            foreach ($meminfo === false ? [] : $meminfo as $line) {
                if (preg_match('/^MemTotal:\s+(\d+)\s+kB$/', $line, $match) === 1) {
                    return (int) $match[1] * 1024;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $command
     */
    private static function command(string $cwd, array $command): ?string
    {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptorSpec, $pipes, $cwd);

        if (!is_resource($process)) {
            return null;
        }

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            return null;
        }

        $value = trim((string) $output);

        if ($value === '' && trim((string) $error) !== '') {
            return null;
        }

        return $value;
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
