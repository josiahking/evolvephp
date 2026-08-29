<?php declare(strict_types=1);

final class ValidationFailure extends RuntimeException
{
}

/**
 * @return never
 */
function fail(string $message): void
{
    throw new ValidationFailure($message);
}

/**
 * @return array{root: string}
 */
function parseArguments(array $arguments): array
{
    $root = null;

    foreach (array_slice($arguments, 1) as $argument) {
        if (strpos($argument, '--root=') === 0) {
            $root = substr($argument, strlen('--root='));
            continue;
        }

        fail('Unknown CLI option: ' . $argument);
    }

    if ($root === null) {
        $root = dirname(__DIR__);
    }

    $resolvedRoot = realpath($root);

    if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
        fail('Repository root does not exist: ' . $root);
    }

    return array('root' => $resolvedRoot);
}

function pathFor(string $root, string $relativePath): string
{
    return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
}

function normalizeRelativePath(string $path): string
{
    return str_replace('\\', '/', trim($path, "/\\"));
}

/**
 * @return mixed
 */
function readJson(string $path, string $label)
{
    if (!is_file($path)) {
        fail($label . ' does not exist.');
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        fail($label . ' is not readable.');
    }

    $decoded = json_decode($contents, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        fail($label . ' contains malformed JSON: ' . json_last_error_msg() . '.');
    }

    return $decoded;
}

/**
 * @return array<string, array{name: string, directory: string}>
 */
function expectedPackages(): array
{
    return array(
        'evolvephp/contracts' => array('name' => 'evolvephp/contracts', 'directory' => 'packages/contracts'),
        'evolvephp/core' => array('name' => 'evolvephp/core', 'directory' => 'packages/core'),
        'evolvephp/module' => array('name' => 'evolvephp/module', 'directory' => 'packages/module'),
        'evolvephp/plugin' => array('name' => 'evolvephp/plugin', 'directory' => 'packages/plugin'),
        'evolvephp/http' => array('name' => 'evolvephp/http', 'directory' => 'packages/http'),
        'evolvephp/testing' => array('name' => 'evolvephp/testing', 'directory' => 'packages/testing'),
        'evolvephp/dev-tools' => array('name' => 'evolvephp/dev-tools', 'directory' => 'packages/dev-tools'),
    );
}

/**
 * @return array<string, string>
 */
function expectedNamespaces(): array
{
    return array(
        'evolvephp/contracts' => 'Evolve\\Contracts\\',
        'evolvephp/core' => 'Evolve\\Core\\',
        'evolvephp/dev-tools' => 'Evolve\\DevTools\\',
        'evolvephp/module' => 'Evolve\\Module\\',
        'evolvephp/plugin' => 'Evolve\\Plugin\\',
        'evolvephp/http' => 'Evolve\\Http\\',
        'evolvephp/testing' => 'Evolve\\Testing\\',
    );
}

/**
 * @return array<string, list<string>>
 */
function expectedGraph(): array
{
    return array(
        'evolvephp/contracts' => array(),
        'evolvephp/core' => array('evolvephp/contracts'),
        'evolvephp/module' => array('evolvephp/contracts'),
        'evolvephp/plugin' => array('evolvephp/contracts'),
        'evolvephp/http' => array('evolvephp/contracts', 'evolvephp/core'),
        'evolvephp/testing' => array('evolvephp/contracts', 'evolvephp/core', 'evolvephp/http', 'evolvephp/module', 'evolvephp/plugin'),
        'evolvephp/dev-tools' => array('evolvephp/contracts', 'evolvephp/core', 'evolvephp/module', 'evolvephp/plugin'),
    );
}

/**
 * @return list<array{name: string, directory: string}>
 */
function validateMap(string $root): array
{
    $mapPath = pathFor($root, 'release-packages.json');
    $map = readJson($mapPath, 'release-packages.json');

    if (!is_array($map)) {
        fail('release-packages.json must decode to a JSON object.');
    }

    if (array_keys($map) !== array('version', 'packages')) {
        fail('release-packages.json must contain only version and packages.');
    }

    if ($map['version'] !== 1) {
        fail('release-packages.json version must be exactly 1.');
    }

    if (!is_array($map['packages']) || count($map['packages']) !== 7) {
        fail('release-packages.json must contain exactly seven package entries.');
    }

    $expectedPackages = array_values(expectedPackages());
    $names = array();
    $directories = array();
    $packages = array();

    foreach ($map['packages'] as $index => $package) {
        $label = 'release-packages.json package entry ' . ($index + 1);

        if (!is_array($package)) {
            fail($label . ' must be a JSON object.');
        }

        if (array_keys($package) !== array('name', 'directory')) {
            fail($label . ' must contain only name and directory.');
        }

        if (!is_string($package['name']) || $package['name'] === '') {
            fail($label . ' must contain a package name.');
        }

        if (!is_string($package['directory']) || $package['directory'] === '') {
            fail($label . ' must contain a package directory.');
        }

        if (isset($names[$package['name']])) {
            fail($label . ' duplicates package name ' . $package['name'] . '.');
        }

        if (isset($directories[$package['directory']])) {
            fail($label . ' duplicates package directory ' . $package['directory'] . '.');
        }

        if (preg_match('/^(?:[A-Za-z]:)?[\/\\\\]/', $package['directory']) === 1) {
            fail($label . ' uses an absolute package directory.');
        }

        $normalizedDirectory = normalizeRelativePath($package['directory']);
        $parts = explode('/', $normalizedDirectory);

        if (in_array('..', $parts, true)) {
            fail($label . ' uses parent-directory traversal.');
        }

        if (!is_file(pathFor($root, $normalizedDirectory . '/composer.json'))) {
            fail($package['name'] . ' mapped directory must contain composer.json.');
        }

        $names[$package['name']] = true;
        $directories[$package['directory']] = true;
        $packages[] = array('name' => $package['name'], 'directory' => $normalizedDirectory);
    }

    $expectedByName = expectedPackages();

    foreach ($packages as $package) {
            if (!isset($expectedByName[$package['name']])) {
            fail('release-packages.json maps unknown package ' . $package['name'] . '.');
        }
    }

    if ($packages !== $expectedPackages) {
        fail('release-packages.json must use the canonical Phase 2.10A package order.');
    }

    validatePackageManifestCoverage($root, $packages);

    return $packages;
}

/**
 * @param list<array{name: string, directory: string}> $packages
 */
function validatePackageManifestCoverage(string $root, array $packages): void
{
    $mappedByDirectory = array();
    $mappedByName = array();

    foreach ($packages as $package) {
        $mappedByDirectory[$package['directory']] = true;
        $mappedByName[$package['name']] = true;
    }

    $manifests = glob(pathFor($root, 'packages/*/composer.json'));

    if ($manifests === false) {
        fail('Unable to scan direct package composer manifests.');
    }

    foreach ($manifests as $manifestPath) {
        $directory = normalizeRelativePath(substr(dirname($manifestPath), strlen($root) + 1));
        $manifest = readJson($manifestPath, $directory . '/composer.json');

        if (!is_array($manifest) || !isset($manifest['name']) || !is_string($manifest['name'])) {
            fail($directory . '/composer.json must contain a package name.');
        }

        if (!isset($mappedByDirectory[$directory])) {
            fail($directory . ' contains composer.json but is missing from release-packages.json.');
        }

        if (!isset($mappedByName[$manifest['name']])) {
            fail($manifest['name'] . ' is not represented in release-packages.json.');
        }
    }
}

/**
 * @param list<array{name: string, directory: string}> $packages
 */
function validatePackages(string $root, array $packages): void
{
    $graph = array();
    $mappedNames = array();

    foreach ($packages as $package) {
        $mappedNames[$package['name']] = true;
    }

    foreach ($packages as $package) {
        $manifestPath = pathFor($root, $package['directory'] . '/composer.json');
        $manifest = readJson($manifestPath, $package['directory'] . '/composer.json');

        if (!is_array($manifest)) {
            fail($package['directory'] . '/composer.json must decode to a JSON object.');
        }

        validateManifest($package, $manifest, $mappedNames);
        validatePackageFiles($root, $package);

        $graph[$package['name']] = internalDependencies($manifest);
    }

    validateExactGraph($graph);
    validateAcyclicGraph($graph);
    validateTopologicalOrder($packages, $graph);
}

/**
 * @param array{name: string, directory: string} $package
 * @param array<string, mixed> $manifest
 * @param array<string, bool> $mappedNames
 */
function validateManifest(array $package, array $manifest, array $mappedNames): void
{
    $label = $package['directory'] . '/composer.json';

    if (($manifest['name'] ?? null) !== $package['name']) {
        fail($label . ' package name must match release map entry.');
    }

    if (($manifest['type'] ?? null) !== 'library') {
        fail($label . ' type must be library.');
    }

    if (($manifest['license'] ?? null) !== 'BSD-3-Clause') {
        fail($label . ' license must be BSD-3-Clause.');
    }

    if (!isset($manifest['require']) || !is_array($manifest['require'])) {
        fail($label . ' must contain require.');
    }

    if (($manifest['require']['php'] ?? null) !== '^8.4') {
        fail($label . ' must require PHP ^8.4.');
    }

    if (array_key_exists('version', $manifest)) {
        fail($label . ' must not contain a version property.');
    }

    if (!isset($manifest['description']) || !is_string($manifest['description']) || trim($manifest['description']) === '') {
        fail($label . ' must contain a non-empty description.');
    }

    $namespace = expectedNamespaces()[$package['name']];

    if (($manifest['autoload']['psr-4'] ?? null) !== array($namespace => 'src/')) {
        fail($label . ' must map ' . $namespace . ' to src/.');
    }

    foreach ($manifest['require'] as $dependency => $constraint) {
        if (strpos((string) $dependency, 'evolvephp/') !== 0) {
            continue;
        }

        if (!isset($mappedNames[$dependency])) {
            fail($label . ' references unmapped internal dependency ' . $dependency . '.');
        }

        if ($constraint !== '^2.0') {
            fail($label . ' requires ' . $dependency . ' with ' . $constraint . '; expected ^2.0.');
        }

        if ($package['name'] !== 'evolvephp/testing' && $dependency === 'evolvephp/testing') {
            fail($label . ' production packages must not require evolvephp/testing.');
        }
    }
}

/**
 * @param array<string, mixed> $manifest
 * @return list<string>
 */
function internalDependencies(array $manifest): array
{
    $dependencies = array();

    foreach ($manifest['require'] as $dependency => $constraint) {
        if ($dependency !== 'php' && strpos((string) $dependency, 'evolvephp/') === 0) {
            $dependencies[] = (string) $dependency;
        }
    }

    sort($dependencies);

    return $dependencies;
}

/**
 * @param array<string, list<string>> $graph
 */
function validateExactGraph(array $graph): void
{
    $expectedGraph = expectedGraph();

    foreach ($expectedGraph as $package => $dependencies) {
        $actual = $graph[$package] ?? null;
        sort($dependencies);

        if ($actual !== $dependencies) {
            fail($package . ' internal dependency graph does not match Phase 2.10A policy.');
        }
    }
}

/**
 * @param array<string, list<string>> $graph
 */
function validateAcyclicGraph(array $graph): void
{
    $visiting = array();
    $visited = array();

    foreach (array_keys($graph) as $package) {
        visitPackage($package, $graph, $visiting, $visited);
    }
}

/**
 * @param array<string, list<string>> $graph
 * @param array<string, bool> $visiting
 * @param array<string, bool> $visited
 */
function visitPackage(string $package, array $graph, array &$visiting, array &$visited): void
{
    if (isset($visited[$package])) {
        return;
    }

    if (isset($visiting[$package])) {
        fail('Package dependency graph contains a cycle at ' . $package . '.');
    }

    $visiting[$package] = true;

    foreach ($graph[$package] as $dependency) {
        visitPackage($dependency, $graph, $visiting, $visited);
    }

    unset($visiting[$package]);
    $visited[$package] = true;
}

/**
 * @param list<array{name: string, directory: string}> $packages
 * @param array<string, list<string>> $graph
 */
function validateTopologicalOrder(array $packages, array $graph): void
{
    $seen = array();

    foreach ($packages as $package) {
        foreach ($graph[$package['name']] as $dependency) {
            if (!isset($seen[$dependency])) {
                fail($package['name'] . ' appears before dependency ' . $dependency . ' in release package order.');
            }
        }

        $seen[$package['name']] = true;
    }
}

/**
 * @param array{name: string, directory: string} $package
 */
function validatePackageFiles(string $root, array $package): void
{
    $directory = pathFor($root, $package['directory']);

    foreach (array('README.md', 'LICENSE.md') as $file) {
        if (!is_file($directory . DIRECTORY_SEPARATOR . $file)) {
            fail($package['name'] . ' must contain ' . $file . '.');
        }
    }

    foreach (array('src', 'tests') as $subdirectory) {
        if (!is_dir($directory . DIRECTORY_SEPARATOR . $subdirectory)) {
            fail($package['name'] . ' must contain ' . $subdirectory . '/.');
        }
    }

    $rootLicence = file_get_contents(pathFor($root, 'LICENSE.md'));
    $packageLicence = file_get_contents($directory . DIRECTORY_SEPARATOR . 'LICENSE.md');

    if ($rootLicence === false) {
        fail('Root LICENSE.md is not readable.');
    }

    if ($packageLicence === false || $packageLicence !== $rootLicence) {
        fail($package['name'] . ' LICENSE.md must match root LICENSE.md byte-for-byte.');
    }

    validateReadme($package, $directory . DIRECTORY_SEPARATOR . 'README.md');
    validateGeneratedFiles($package, $directory);
}

/**
 * @param array{name: string, directory: string} $package
 */
function validateReadme(array $package, string $readmePath): void
{
    $content = file_get_contents($readmePath);

    if ($content === false) {
        fail($package['name'] . ' README.md is not readable.');
    }

    if (strpos($content, $package['name']) === false) {
        fail($package['name'] . ' README.md must contain the package identifier.');
    }

    if (strpos($content, 'PHP `^8.4`') === false && strpos($content, 'PHP ^8.4') === false) {
        fail($package['name'] . ' README.md must document PHP ^8.4.');
    }

    if (preg_match('/not yet independently published/i', $content) !== 1) {
        fail($package['name'] . ' README.md must state that independent publication has not begun yet.');
    }

    if (preg_match('/github\.com\/josiahking\/evolvephp[-\/](?:contracts|core|dev-tools|http|module|plugin|testing)/i', $content) === 1) {
        fail($package['name'] . ' README.md must not claim a split repository URL.');
    }

    if (preg_match('/composer require/i', $content) === 1) {
        fail($package['name'] . ' README.md must not present a public install command.');
    }
}

/**
 * @param array{name: string, directory: string} $package
 */
function validateGeneratedFiles(array $package, string $directory): void
{
    $forbiddenNames = array(
        'vendor',
        '.phpunit.cache',
        '.phpstan-cache',
        '.php-cs-fixer.cache',
        '.deptrac.cache',
        'split',
        'split-tree',
        'dist',
    );

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        $name = $file->getFilename();
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (in_array($name, $forbiddenNames, true) || in_array($extension, array('zip', 'tar', 'gz', 'bz2'), true)) {
            fail($package['name'] . ' contains generated publication artifact ' . $name . '.');
        }
    }
}

try {
    $arguments = parseArguments($argv);
    $packages = validateMap($arguments['root']);
    validatePackages($arguments['root'], $packages);

    echo 'EvolvePHP release package validation passed.' . PHP_EOL;
    echo 'Packages: ' . count($packages) . PHP_EOL;
    echo 'Release order:' . PHP_EOL;

    foreach ($packages as $index => $package) {
        echo ($index + 1) . '. ' . $package['name'] . PHP_EOL;
    }

    exit(0);
} catch (ValidationFailure $failure) {
    fwrite(STDERR, 'EvolvePHP release package validation failed: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
