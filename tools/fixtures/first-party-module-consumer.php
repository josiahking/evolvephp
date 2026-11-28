<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Evolve\ApiKeys\ApiKeysModule;
use Evolve\AuditLog\AuditLogModule;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Core\ApplicationKernel;
use Evolve\Core\Component\ComponentBootstrapper;
use Evolve\Core\Configuration\ArrayConfiguration;
use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Module\ModuleDefinition;
use Evolve\Module\ModuleDescriptor;
use Evolve\Webhooks\WebhooksModule;

function demand(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return list<string> */
function moduleNames(string $slug): array
{
    $shared = ['evolvephp/contracts', 'evolvephp/core', 'evolvephp/database-contracts',
        'evolvephp/i18n', 'evolvephp/lock-contracts', 'evolvephp/migration', 'evolvephp/module'];
    $extra = match ($slug) {
        'audit-log' => ['evolvephp/audit-log'],
        'webhooks' => ['evolvephp/secret-contracts', 'evolvephp/webhooks'],
        'api-keys' => ['evolvephp/api-keys'],
        default => throw new RuntimeException('Unknown first-party module: ' . $slug),
    };
    $names = array_merge($shared, $extra, ['psr/container']);
    sort($names);
    return $names;
}

/**
 * @param list<mixed> $records
 * @return array<string, array<string, mixed>>
 */
function uniquePackages(array $records): array
{
    $packages = [];
    foreach ($records as $record) {
        demand(is_array($record) && is_string($record['name'] ?? null) && $record['name'] !== '', 'Invalid Composer package record.');
        $name = $record['name'];
        demand(!isset($packages[$name]), 'Duplicate Composer package record: ' . $name);
        $packages[$name] = $record;
    }
    return $packages;
}

/** @return array<string, string> */
function packageFiles(string $directory): array
{
    $files = [];
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($walk as $entry) {
        demand(!$entry->isLink() && $entry->isFile(), 'Unsafe or unsupported package content: ' . $entry->getPathname());
        $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($directory) + 1));
        $files[$relative] = hash_file('sha256', $entry->getPathname());
    }
    ksort($files);
    return $files;
}

function safeDirectory(string $path, string $root, string $label): string
{
    $resolvedRoot = realpath($root);
    $resolved = realpath($path);
    demand(
        $resolvedRoot !== false && $resolved !== false && is_dir($resolved)
        && str_starts_with(strtolower(str_replace('\\', '/', $resolved)), rtrim(strtolower(str_replace('\\', '/', $resolvedRoot)), '/') . '/'),
        'Unsafe ' . $label,
    );
    $current = $path;
    while (strlen($current) >= strlen($root)) {
        demand(!is_link($current), 'Unsafe linked ' . $label);
        if (rtrim(str_replace('\\', '/', $current), '/') === rtrim(str_replace('\\', '/', $root), '/')) {
            break;
        }
        $parent = dirname($current);
        demand($parent !== $current, 'Unsafe ' . $label);
        $current = $parent;
    }
    return $resolved;
}

function verifyInstalled(string $application, string $slug, bool $exact, string $firstPartyRoot, string $thirdPartyRoot = ''): void
{
    $autoload = $application . '/vendor/autoload.php';
    demand(is_file($autoload), 'Independent consumer vendor/autoload.php is required.');
    $expected = moduleNames($slug);
    $lock = json_decode((string) file_get_contents($application . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    $installed = json_decode((string) file_get_contents($application . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
    demand(is_array($lock['packages'] ?? null) && is_array($installed['packages'] ?? null), 'Composer package records are missing.');
    demand(is_array($lock['packages-dev'] ?? null), 'Composer development package records are missing.');
    $locked = uniquePackages(array_merge($lock['packages'], $lock['packages-dev']));
    $actual = uniquePackages($installed['packages']);
    if ($exact) {
        $names = array_keys($locked);
        sort($names);
        demand($names === $expected, 'Independent consumer dependency closure changed.');
        $names = array_keys($actual);
        sort($names);
        demand($names === $expected, 'Independent consumer installed package closure changed.');
        demand(($lock['packages-dev'] ?? []) === [], 'Independent consumer installed development packages.');
    }
    $vendor = realpath($application . '/vendor');
    demand($vendor !== false && !is_link($application . '/vendor'), 'Installed vendor root is unsafe.');
    foreach ($expected as $name) {
        $version = str_starts_with($name, 'evolvephp/') ? '2.0.0-beta.1' : '2.0.2';
        demand(($locked[$name]['version'] ?? null) === $version, 'Wrong locked version: ' . $name);
        demand(($actual[$name]['version'] ?? null) === $version, 'Wrong installed version: ' . $name);
        $checkSnapshot = $exact || $name === 'evolvephp/' . $slug;
        if ($checkSnapshot) {
            $snapshotRoot = $exact
                ? (str_starts_with($name, 'evolvephp/') ? $firstPartyRoot : $thirdPartyRoot)
                : $firstPartyRoot;
            $snapshotPath = $snapshotRoot . '/' . ($exact ? (str_starts_with($name, 'evolvephp/') ? str_replace('/', '-', $name) : $name) : $slug);
            $snapshot = safeDirectory($snapshotPath, $snapshotRoot, 'package snapshot origin: ' . $name);
            $url = $locked[$name]['dist']['url'] ?? null;
            demand(($locked[$name]['dist']['type'] ?? null) === 'path' && is_string($url) && $url !== '', 'Wrong locked package snapshot origin: ' . $name);
            demand(!in_array('..', explode('/', str_replace('\\', '/', $url)), true), 'Unsafe locked package snapshot origin: ' . $name);
            $origin = str_starts_with($url, '/') || preg_match('~^[A-Za-z]:[/\\\\]~', $url) ? $url : $application . '/' . $url;
            demand(!is_link($origin) && realpath($origin) === $snapshot, 'Wrong locked package snapshot origin: ' . $name);
            safeDirectory($origin, $snapshotRoot, 'locked package snapshot origin: ' . $name);
        }
        $path = $application . '/vendor/' . $name;
        $resolved = realpath($path);
        demand($resolved !== false, 'Unsafe installed package origin: ' . $name);
        safeDirectory($path, $vendor, 'installed package origin: ' . $name);
        $manifest = json_decode((string) file_get_contents($path . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        demand(($manifest['name'] ?? null) === $name, 'Wrong installed package identity: ' . $name);
        if ($checkSnapshot) {
            demand(packageFiles($snapshot) === packageFiles($resolved), 'Installed package content differs from snapshot: ' . $name);
        }
    }
    require_once $autoload;
    foreach ($expected as $name) {
        $version = str_starts_with($name, 'evolvephp/') ? '2.0.0-beta.1' : '2.0.2';
        demand(InstalledVersions::getPrettyVersion($name) === $version, 'Autoloader package version mismatch: ' . $name);
    }
    $entry = match ($slug) {
        'audit-log' => AuditLogModule::class,
        'webhooks' => WebhooksModule::class,
        'api-keys' => ApiKeysModule::class,
        default => throw new RuntimeException('Unknown first-party module: ' . $slug),
    };
    $source = realpath((string) (new ReflectionClass($entry))->getFileName());
    $package = realpath($application . '/vendor/evolvephp/' . $slug);
    demand($source !== false && $package !== false && str_starts_with($source, $package . DIRECTORY_SEPARATOR), 'Module autoload origin is incorrect: ' . $slug);
}

function verifyComposition(string $application, string $snapshotRoot): void
{
    foreach (['audit-log', 'webhooks', 'api-keys'] as $slug) {
        verifyInstalled($application, $slug, false, $snapshotRoot);
    }
    $entries = [
        'audit-log' => AuditLogModule::class,
        'webhooks' => WebhooksModule::class,
        'api-keys' => ApiKeysModule::class,
    ];
    $definitions = [];
    foreach ($entries as $slug => $entry) {
        $definitions[] = new ModuleDefinition(new ModuleDescriptor(
            new ComponentIdentifier('app/' . $slug),
            ucfirst($slug),
            2,
        ), $entry);
    }
    $empty = new ServiceRegistry();
    $disabled = new ApplicationKernel(new ArrayConfiguration(), [], $empty, new ComponentBootstrapper($definitions));
    $disabled->boot();
    demand($empty->definitionIdentifiers() === [], 'Omitted modules registered services.');
    $disabled->shutdown();

    $auditOnly = new ServiceRegistry();
    $kernel = new ApplicationKernel(
        new ArrayConfiguration(['evolve' => ['components' => ['enabled' => ['app/audit-log']]]]),
        [],
        $auditOnly,
        new ComponentBootstrapper($definitions),
    );
    $kernel->boot();
    $ids = $auditOnly->definitionIdentifiers();
    demand(in_array(Evolve\AuditLog\AuditLog::class, $ids, true), 'Explicit Audit Log enablement failed.');
    demand(!in_array(Evolve\Webhooks\Webhooks::class, $ids, true), 'Omitted Webhooks module became active.');
    demand(!in_array(Evolve\ApiKeys\ApiKeys::class, $ids, true), 'Omitted API Keys module became active.');
    $kernel->shutdown();

    $all = new ServiceRegistry();
    $sideEffects = [];
    foreach ([Evolve\Database\Contracts\DatabaseConnection::class, Evolve\Migration\MigrationHistoryStore::class,
        Evolve\Webhooks\TrustedTransport::class, Evolve\Webhooks\KeyProvider::class] as $collaborator) {
        $all->register($collaborator, ServiceLifetime::Application, static function () use (&$sideEffects, $collaborator): never {
            $sideEffects[] = $collaborator;
            throw new RuntimeException('Module boot resolved a host-owned side-effect collaborator: ' . $collaborator);
        });
    }
    $kernel = new ApplicationKernel(
        new ArrayConfiguration(['evolve' => ['components' => ['enabled' => ['app/audit-log', 'app/webhooks', 'app/api-keys']]]]),
        [],
        $all,
        new ComponentBootstrapper($definitions),
    );
    $kernel->boot();
    $ids = $all->definitionIdentifiers();
    foreach ([Evolve\AuditLog\AuditLog::class, Evolve\Webhooks\Webhooks::class, Evolve\ApiKeys\ApiKeys::class] as $service) {
        demand(in_array($service, $ids, true), 'Explicit module registration failed: ' . $service);
    }
    $kernel->shutdown();
    demand($sideEffects === [], 'Module boot automatically ran migrations or network delivery.');
}

try {
    [, $mode, $application, $slug, $firstPartyRoot, $thirdPartyRoot] = array_pad($argv, 6, '');
    if ($mode === 'verify') {
        verifyInstalled($application, $slug, true, $firstPartyRoot, $thirdPartyRoot);
        echo 'Installed ' . $slug . ' package closure and provenance passed.' . PHP_EOL;
    } elseif ($mode === 'verify-skeleton') {
        verifyInstalled($application, $slug, false, $firstPartyRoot);
        echo 'Installed ' . $slug . ' skeleton provenance passed.' . PHP_EOL;
    } elseif ($mode === 'compose') {
        verifyComposition($application, $firstPartyRoot);
        echo 'Explicit application module composition passed.' . PHP_EOL;
    } else {
        throw new RuntimeException('Expected verify or compose mode.');
    }
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage() . PHP_EOL);
    exit(1);
}
