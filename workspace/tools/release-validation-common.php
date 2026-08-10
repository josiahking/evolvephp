<?php declare(strict_types=1);

final class ReleaseValidationFailure extends RuntimeException
{
}

final class ReleaseValidationProcessResult
{
    /**
     * @param list<string> $command
     */
    public function __construct(
        public readonly array $command,
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }

    public function output(): string
    {
        return trim($this->stdout . "\n" . $this->stderr);
    }
}

final class ReleaseValidationProcessRunner
{
    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     */
    public function run(array $command, ?string $workingDirectory = null, array $environment = array()): ReleaseValidationProcessResult
    {
        if ($command === array()) {
            throw new ReleaseValidationFailure('Cannot run an empty process command.');
        }

        foreach ($command as $argument) {
            if (!is_string($argument) || $argument === '') {
                throw new ReleaseValidationFailure('Process commands must contain non-empty string arguments.');
            }
        }

        $descriptorSpec = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );

        $processEnvironment = null;

        if ($environment !== array()) {
            $baseEnvironment = getenv();
            $processEnvironment = array_merge(is_array($baseEnvironment) ? $baseEnvironment : array(), $environment);
        }

        $process = proc_open(
            $command,
            $descriptorSpec,
            $pipes,
            $workingDirectory,
            $processEnvironment,
            array('bypass_shell' => true)
        );

        if (!is_resource($process)) {
            throw new ReleaseValidationFailure('Unable to start process: ' . describeCommand($command));
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return new ReleaseValidationProcessResult(
            $command,
            $exitCode,
            is_string($stdout) ? $stdout : '',
            is_string($stderr) ? $stderr : ''
        );
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     */
    public function mustRun(array $command, ?string $workingDirectory = null, array $environment = array()): ReleaseValidationProcessResult
    {
        $result = $this->run($command, $workingDirectory, $environment);

        if ($result->exitCode !== 0) {
            throw new ReleaseValidationFailure(
                'Process failed with exit code ' . $result->exitCode . ': ' . describeCommand($command) . "\n" . $result->output()
            );
        }

        return $result;
    }
}

final class ReleaseValidationTemporaryDirectory
{
    private bool $removed = false;

    public function __construct(public readonly string $path)
    {
    }

    public function child(string $relativePath): string
    {
        return joinPaths($this->path, $relativePath);
    }

    public function cleanup(): void
    {
        if ($this->removed) {
            return;
        }

        removeDirectory($this->path, $this->path);
        $this->removed = true;
    }
}

/**
 * @return never
 */
function releaseValidationFail(string $message): void
{
    throw new ReleaseValidationFailure($message);
}

/**
 * @param list<string> $command
 */
function describeCommand(array $command): string
{
    return implode(' ', array_map(static function (string $argument): string {
        return preg_match('/\s/', $argument) === 1 ? '"' . $argument . '"' : $argument;
    }, $command));
}

function repositoryRootDefault(): string
{
    $root = realpath(dirname(__DIR__, 2));

    if ($root === false) {
        releaseValidationFail('Unable to determine repository root.');
    }

    return $root;
}

/**
 * @return array{root: string, ref: string, composer: string|null}
 */
function parseReleaseValidationArguments(array $argv, bool $allowRef = true): array
{
    $options = array(
        'root' => repositoryRootDefault(),
        'ref' => 'HEAD',
        'composer' => null,
    );

    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with($argument, '--root=')) {
            $root = realpath(substr($argument, strlen('--root=')));

            if ($root === false || !is_dir($root)) {
                releaseValidationFail('Repository root does not exist: ' . substr($argument, strlen('--root=')));
            }

            $options['root'] = $root;
            continue;
        }

        if ($allowRef && str_starts_with($argument, '--ref=')) {
            $ref = substr($argument, strlen('--ref='));

            if ($ref === '') {
                releaseValidationFail('--ref must not be empty.');
            }

            $options['ref'] = $ref;
            continue;
        }

        if (str_starts_with($argument, '--composer=')) {
            $composer = substr($argument, strlen('--composer='));

            if ($composer === '') {
                releaseValidationFail('--composer must not be empty.');
            }

            if ($composer !== 'composer') {
                $resolvedComposer = realpath($composer);

                if ($resolvedComposer === false || !is_file($resolvedComposer)) {
                    releaseValidationFail('Composer path does not exist: ' . $composer);
                }

                $composer = $resolvedComposer;
            }

            $options['composer'] = $composer;
            continue;
        }

        releaseValidationFail('Unknown CLI option: ' . $argument);
    }

    return $options;
}

function joinPaths(string $base, string $relativePath): string
{
    return rtrim($base, "\\/") . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
}

function normalizePath(string $path): string
{
    return str_replace('\\', '/', $path);
}

function normalizeRelativePath(string $path): string
{
    return trim(normalizePath($path), '/');
}

/**
 * @return mixed
 */
function readJsonFile(string $path, string $label)
{
    if (!is_file($path)) {
        releaseValidationFail($label . ' does not exist.');
    }

    $contents = file_get_contents($path);

    if ($contents === false) {
        releaseValidationFail($label . ' is not readable.');
    }

    try {
        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        releaseValidationFail($label . ' contains malformed JSON: ' . $exception->getMessage());
    }
}

/**
 * @return list<array{name: string, directory: string}>
 */
function loadReleasePackages(string $root): array
{
    $map = readJsonFile(joinPaths($root, 'workspace/release-packages.json'), 'workspace/release-packages.json');

    if (!is_array($map) || array_keys($map) !== array('version', 'packages')) {
        releaseValidationFail('workspace/release-packages.json must contain only version and packages.');
    }

    if (($map['version'] ?? null) !== 1) {
        releaseValidationFail('workspace/release-packages.json version must be exactly 1.');
    }

    if (!is_array($map['packages']) || count($map['packages']) !== 6) {
        releaseValidationFail('workspace/release-packages.json must contain exactly six package entries.');
    }

    $expected = array(
        array('name' => 'evolvephp/contracts', 'directory' => 'packages/contracts'),
        array('name' => 'evolvephp/core', 'directory' => 'packages/core'),
        array('name' => 'evolvephp/module', 'directory' => 'packages/module'),
        array('name' => 'evolvephp/plugin', 'directory' => 'packages/plugin'),
        array('name' => 'evolvephp/http', 'directory' => 'packages/http'),
        array('name' => 'evolvephp/testing', 'directory' => 'packages/testing'),
    );

    $packages = array();
    $seenNames = array();
    $seenDirectories = array();

    foreach ($map['packages'] as $index => $package) {
        if (!is_array($package) || array_keys($package) !== array('name', 'directory')) {
            releaseValidationFail('workspace/release-packages.json package entry ' . ($index + 1) . ' must contain only name and directory.');
        }

        if (!is_string($package['name']) || !is_string($package['directory'])) {
            releaseValidationFail('workspace/release-packages.json package entry ' . ($index + 1) . ' must contain string name and directory.');
        }

        $directory = normalizeRelativePath($package['directory']);

        if (preg_match('/^(?:[A-Za-z]:)?[\/\\\\]/', $package['directory']) === 1 || in_array('..', explode('/', $directory), true)) {
            releaseValidationFail($package['name'] . ' uses an unsafe package directory.');
        }

        if (isset($seenNames[$package['name']]) || isset($seenDirectories[$directory])) {
            releaseValidationFail('workspace/release-packages.json contains duplicate package names or directories.');
        }

        if (!is_file(joinPaths($root, $directory . '/composer.json'))) {
            releaseValidationFail($package['name'] . ' mapped directory must contain composer.json.');
        }

        $seenNames[$package['name']] = true;
        $seenDirectories[$directory] = true;
        $packages[] = array('name' => $package['name'], 'directory' => $directory);
    }

    if ($packages !== $expected) {
        releaseValidationFail('workspace/release-packages.json must use the canonical Phase 2.10A package order.');
    }

    return $packages;
}

/**
 * @return list<string>
 */
function composerCommand(?string $composer): array
{
    if ($composer !== null) {
        if (strtolower(substr($composer, -5)) === '.phar') {
            return array(PHP_BINARY, $composer);
        }

        return array($composer);
    }

    $composerBinary = getenv('COMPOSER_BINARY');

    if (is_string($composerBinary) && $composerBinary !== '') {
        if (strtolower(substr($composerBinary, -5)) === '.phar') {
            return array(PHP_BINARY, $composerBinary);
        }

        return array($composerBinary);
    }

    return array('composer');
}

function createTemporaryDirectory(string $prefix = 'evolvephp-release-validation-'): ReleaseValidationTemporaryDirectory
{
    $base = sys_get_temp_dir();
    $candidate = tempnam($base, $prefix);

    if ($candidate === false) {
        releaseValidationFail('Unable to allocate temporary directory name.');
    }

    if (is_file($candidate) && !unlink($candidate)) {
        releaseValidationFail('Unable to prepare temporary directory: ' . $candidate);
    }

    if (!mkdir($candidate, 0777, true)) {
        releaseValidationFail('Unable to create temporary directory: ' . $candidate);
    }

    $real = realpath($candidate);

    if ($real === false) {
        releaseValidationFail('Unable to resolve temporary directory: ' . $candidate);
    }

    return new ReleaseValidationTemporaryDirectory($real);
}

function removeDirectory(string $path, string $ownedRoot): void
{
    $realPath = realpath($path);
    $realRoot = realpath($ownedRoot);

    if ($realPath === false) {
        return;
    }

    if ($realRoot === false || $realPath !== $realRoot && !str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR)) {
        releaseValidationFail('Refusing to remove path outside validator-owned temporary root: ' . $path);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $entry) {
        $entryPath = $entry->getPathname();

        if ($entry->isDir() && !$entry->isLink()) {
            @chmod($entryPath, 0777);

            if (!rmdir($entryPath)) {
                releaseValidationFail('Unable to remove temporary directory: ' . $entryPath);
            }

            continue;
        }

        @chmod($entryPath, 0666);

        if (!unlink($entryPath)) {
            releaseValidationFail('Unable to remove temporary file: ' . $entryPath);
        }
    }

    @chmod($realPath, 0777);

    if (!rmdir($realPath)) {
        releaseValidationFail('Unable to remove temporary root: ' . $realPath);
    }
}

/**
 * @return array{head: string, refs: string, tags: string, status: string}
 */
function captureSourceState(ReleaseValidationProcessRunner $runner, string $root): array
{
    return array(
        'head' => trim($runner->mustRun(array('git', '-C', $root, 'rev-parse', 'HEAD'))->stdout),
        'refs' => trim($runner->mustRun(array('git', '-C', $root, 'show-ref', '--heads', '--tags'))->stdout),
        'tags' => trim($runner->mustRun(array('git', '-C', $root, 'tag', '--list'))->stdout),
        'status' => trim($runner->mustRun(array('git', '-C', $root, 'status', '--short', '--untracked-files=all'))->stdout),
    );
}

/**
 * @param array{head: string, refs: string, tags: string, status: string} $before
 */
function assertSourceStatePreserved(ReleaseValidationProcessRunner $runner, string $root, array $before): void
{
    $after = captureSourceState($runner, $root);

    foreach ($before as $key => $value) {
        if ($after[$key] !== $value) {
            releaseValidationFail('Source repository state changed during validation: ' . $key . '.');
        }
    }
}

/**
 * @return list<string>
 */
function sortedLines(string $text): array
{
    $lines = array_values(array_filter(preg_split('/\r?\n/', trim($text)) ?: array(), static fn (string $line): bool => $line !== ''));
    sort($lines);

    return $lines;
}

function packageSlug(string $packageName): string
{
    return str_replace('evolvephp/', '', $packageName);
}

/**
 * @param list<string> $command
 * @return list<string>
 */
function withComposerCommand(array $command, ?string $composer): array
{
    return array_merge(composerCommand($composer), $command);
}
