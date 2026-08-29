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
    private const DEFAULT_TIMEOUT_SECONDS = 300;

    public function __construct(
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
        if ($timeoutSeconds < 1) {
            throw new ReleaseValidationFailure('Process timeout must be at least 1 second.');
        }
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     */
    public function run(
        array $command,
        ?string $workingDirectory = null,
        array $environment = array(),
        ?string $stage = null,
    ): ReleaseValidationProcessResult
    {
        if ($command === array()) {
            throw new ReleaseValidationFailure('Cannot run an empty process command.');
        }

        foreach ($command as $argument) {
            if (!is_string($argument) || $argument === '') {
                throw new ReleaseValidationFailure('Process commands must contain non-empty string arguments.');
            }
        }

        $stdoutPath = $this->createProcessOutputFile('stdout');
        $stderrPath = $this->createProcessOutputFile('stderr');
        $descriptorSpec = array(
            0 => array('pipe', 'r'),
            1 => array('file', $stdoutPath, 'w'),
            2 => array('file', $stderrPath, 'w'),
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
            $this->removeProcessOutputFiles($stdoutPath, $stderrPath);

            throw new ReleaseValidationFailure('Unable to start process: ' . describeCommand($command));
        }

        fclose($pipes[0]);

        $deadline = microtime(true) + $this->timeoutSeconds;
        $lastStatus = proc_get_status($process);

        try {
            while ($lastStatus['running']) {
                if (microtime(true) >= $deadline) {
                    $this->terminateProcess($process, $lastStatus['pid']);
                    $exitCode = proc_close($process);
                    $stdout = $this->readProcessOutputFile($stdoutPath);
                    $stderr = $this->readProcessOutputFile($stderrPath);

                    throw new ReleaseValidationFailure(
                        'Process timed out after '
                        . $this->formatTimeout()
                        . $this->stageDescription($stage)
                        . ': '
                        . describeCommand($command)
                        . $this->capturedOutputDescription($stdout, $stderr)
                    );
                }

                usleep(10000);
                $lastStatus = proc_get_status($process);
            }

            $exitCode = proc_close($process);

            if ($exitCode === -1 && isset($lastStatus['exitcode']) && is_int($lastStatus['exitcode'])) {
                $exitCode = $lastStatus['exitcode'];
            }

            return new ReleaseValidationProcessResult(
                $command,
                $exitCode,
                $this->readProcessOutputFile($stdoutPath),
                $this->readProcessOutputFile($stderrPath)
            );
        } finally {
            $this->removeProcessOutputFiles($stdoutPath, $stderrPath);
        }
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $environment
     */
    public function mustRun(
        array $command,
        ?string $workingDirectory = null,
        array $environment = array(),
        ?string $stage = null,
    ): ReleaseValidationProcessResult
    {
        $result = $this->run($command, $workingDirectory, $environment, $stage);

        if ($result->exitCode !== 0) {
            throw new ReleaseValidationFailure(
                'Process failed with exit code '
                . $result->exitCode
                . $this->stageDescription($stage)
                . ': '
                . describeCommand($command)
                . "\n"
                . $result->output()
            );
        }

        return $result;
    }

    /**
     * @param resource $process
     */
    private function terminateProcess(mixed $process, int $pid): void
    {
        if (DIRECTORY_SEPARATOR === '\\' && $pid > 0 && $this->terminateWindowsProcessTree($pid)) {
            return;
        }

        proc_terminate($process);
        $deadline = microtime(true) + 1.0;

        do {
            $status = proc_get_status($process);

            if (!$status['running']) {
                return;
            }

            usleep(10000);
        } while (microtime(true) < $deadline);

        proc_terminate($process, 9);
    }

    private function terminateWindowsProcessTree(int $pid): bool
    {
        $process = proc_open(
            array('taskkill', '/PID', (string) $pid, '/T', '/F'),
            array(
                0 => array('pipe', 'r'),
                1 => array('pipe', 'w'),
                2 => array('pipe', 'w'),
            ),
            $pipes,
            null,
            null,
            array('bypass_shell' => true)
        );

        if (!is_resource($process)) {
            return false;
        }

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0;
    }

    private function stageDescription(?string $stage): string
    {
        return $stage === null ? '' : ' during ' . $stage;
    }

    private function formatTimeout(): string
    {
        return $this->timeoutSeconds . ' ' . ($this->timeoutSeconds === 1 ? 'second' : 'seconds');
    }

    private function capturedOutputDescription(string $stdout, string $stderr): string
    {
        $output = trim($stdout . "\n" . $stderr);

        return $output === '' ? '' : "\n" . $output;
    }

    private function createProcessOutputFile(string $label): string
    {
        $path = tempnam(sys_get_temp_dir(), 'evolvephp-process-' . $label . '-');

        if ($path === false) {
            throw new ReleaseValidationFailure('Unable to allocate process ' . $label . ' capture file.');
        }

        return $path;
    }

    private function readProcessOutputFile(string $path): string
    {
        $output = file_get_contents($path);

        return is_string($output) ? $output : '';
    }

    private function removeProcessOutputFiles(string $stdoutPath, string $stderrPath): void
    {
        foreach (array($stdoutPath, $stderrPath) as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
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
    $root = realpath(dirname(__DIR__));

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
 * @return list<array<string, mixed>>
 */
function loadLockedRuntimePackageRepositoryPackages(string $root): array
{
    return loadLockedPackageRepositoryPackages($root);
}

/**
 * @return list<array<string, mixed>>
 */
function loadSkeletonLockedPackageRepositoryPackages(string $root): array
{
    $manifest = readJsonFile(joinPaths($root, 'skeleton/composer.json'), 'skeleton composer.json');
    $lockedPackages = lockedPackageRepositoryPackagesByName($root, true);
    $packageNames = array();
    $queue = array();

    foreach (array('require', 'require-dev') as $section) {
        if (!isset($manifest[$section])) {
            continue;
        }

        if (!is_array($manifest[$section])) {
            releaseValidationFail('skeleton composer.json ' . $section . ' must be an object.');
        }

        foreach (array_keys($manifest[$section]) as $packageName) {
            if (is_string($packageName) && isSkeletonExternalPackageName($packageName)) {
                $queue[] = $packageName;
            }
        }
    }

    foreach (loadReleasePackages($root) as $package) {
        $packageManifest = readJsonFile(joinPaths($root, $package['directory'] . '/composer.json'), $package['name'] . ' composer.json');

        if (!isset($packageManifest['require']) || !is_array($packageManifest['require'])) {
            releaseValidationFail($package['name'] . ' composer.json require must be an object.');
        }

        foreach (array_keys($packageManifest['require']) as $packageName) {
            if (is_string($packageName) && isSkeletonExternalPackageName($packageName)) {
                $queue[] = $packageName;
            }
        }
    }

    while ($queue !== array()) {
        $packageName = array_shift($queue);

        if (!is_string($packageName) || isset($packageNames[$packageName])) {
            continue;
        }

        if (!isset($lockedPackages[$packageName])) {
            releaseValidationFail('Skeleton offline dependency is missing from composer.lock: ' . $packageName);
        }

        $packageNames[$packageName] = true;
        $requires = $lockedPackages[$packageName]['require'] ?? array();

        if (!is_array($requires)) {
            releaseValidationFail('composer.lock package ' . $packageName . ' require field must be an object.');
        }

        foreach (array_keys($requires) as $requiredPackageName) {
            if (is_string($requiredPackageName) && isSkeletonExternalPackageName($requiredPackageName)) {
                $queue[] = $requiredPackageName;
            }
        }
    }

    $selected = array();

    foreach (array_keys($packageNames) as $packageName) {
        $vendorPath = joinPaths($root, 'vendor/' . $packageName);

        if (!is_dir($vendorPath)) {
            releaseValidationFail('Locked package is not installed locally for offline skeleton validation: ' . $packageName);
        }

        $selected[$packageName] = $lockedPackages[$packageName];
    }

    ksort($selected);

    return array_values($selected);
}

/**
 * @return list<array<string, mixed>>
 */
function loadLockedPackageRepositoryPackages(string $root, bool $includeDev = false): array
{
    return array_values(lockedPackageRepositoryPackagesByName($root, $includeDev));
}

/**
 * @return array<string, array<string, mixed>>
 */
function lockedPackageRepositoryPackagesByName(string $root, bool $includeDev = false): array
{
    $lock = readJsonFile(joinPaths($root, 'composer.lock'), 'composer.lock');

    if (!is_array($lock)) {
        releaseValidationFail('composer.lock must decode to an object.');
    }

    foreach (array('packages', 'packages-dev') as $section) {
        if (!array_key_exists($section, $lock) || !is_array($lock[$section])) {
            releaseValidationFail('composer.lock ' . $section . ' must be an array.');
        }
    }

    $packages = array();
    $sections = $includeDev ? array('packages', 'packages-dev') : array('packages');

    foreach ($sections as $section) {
        foreach ($lock[$section] as $index => $package) {
            $entry = $index + 1;

            if (!is_array($package)) {
                releaseValidationFail('composer.lock ' . $section . ' entry ' . $entry . ' must be an object.');
            }

            $name = $package['name'] ?? null;

            if (!is_string($name) || $name === '') {
                releaseValidationFail('composer.lock ' . $section . ' entry ' . $entry . ' must contain a non-empty name.');
            }

            if (str_starts_with($name, 'evolvephp/') || isPlatformPackageName($name)) {
                continue;
            }

            $version = $package['version'] ?? null;

            if (!is_string($version) || $version === '') {
                releaseValidationFail('composer.lock package ' . $name . ' must contain a non-empty version.');
            }

            if (isset($packages[$name])) {
                releaseValidationFail('composer.lock contains duplicate package repository metadata for ' . $name . '.');
            }

            $definition = array(
                'name' => $name,
                'version' => $version,
            );

            foreach (array('require', 'conflict', 'replace', 'provide') as $field) {
                if (!array_key_exists($field, $package)) {
                    continue;
                }

                if (!is_array($package[$field])) {
                    releaseValidationFail('composer.lock package ' . $name . ' field ' . $field . ' must be an object.');
                }

                $definition[$field] = sortedAssociativeArray($package[$field]);
            }

            if (array_key_exists('type', $package)) {
                if (!is_string($package['type']) || $package['type'] === '') {
                    releaseValidationFail('composer.lock package ' . $name . ' field type must be a non-empty string.');
                }

                $definition['type'] = $package['type'];
            }

            foreach (array('source', 'dist') as $field) {
                if (!array_key_exists($field, $package)) {
                    continue;
                }

                if (!is_array($package[$field]) || $package[$field] === array()) {
                    releaseValidationFail('composer.lock package ' . $name . ' field ' . $field . ' must be an object.');
                }

                $definition[$field] = sortedAssociativeArray($package[$field]);
            }

            if (!isset($definition['source']) && !isset($definition['dist'])) {
                releaseValidationFail('composer.lock package ' . $name . ' must contain source or dist metadata for offline package repositories.');
            }

            $packages[$name] = $definition;
        }
    }

    ksort($packages);

    return $packages;
}

function isSkeletonExternalPackageName(string $name): bool
{
    return !str_starts_with($name, 'evolvephp/') && !isPlatformPackageName($name);
}

function isPlatformPackageName(string $name): bool
{
    return $name === 'php'
        || $name === 'hhvm'
        || str_starts_with($name, 'composer-')
        || str_starts_with($name, 'ext-')
        || str_starts_with($name, 'lib-')
        || str_starts_with($name, 'php-');
}

/**
 * @param array<string, mixed> $values
 * @return array<string, mixed>
 */
function sortedAssociativeArray(array $values): array
{
    ksort($values);

    return $values;
}

/**
 * @return list<array{name: string, directory: string}>
 */
function loadReleasePackages(string $root): array
{
    $map = readJsonFile(joinPaths($root, 'release-packages.json'), 'release-packages.json');

    if (!is_array($map) || array_keys($map) !== array('version', 'packages')) {
        releaseValidationFail('release-packages.json must contain only version and packages.');
    }

    if (($map['version'] ?? null) !== 1) {
        releaseValidationFail('release-packages.json version must be exactly 1.');
    }

    if (!is_array($map['packages']) || count($map['packages']) !== 7) {
        releaseValidationFail('release-packages.json must contain exactly seven package entries.');
    }

    $expected = array(
        array('name' => 'evolvephp/contracts', 'directory' => 'packages/contracts'),
        array('name' => 'evolvephp/core', 'directory' => 'packages/core'),
        array('name' => 'evolvephp/module', 'directory' => 'packages/module'),
        array('name' => 'evolvephp/plugin', 'directory' => 'packages/plugin'),
        array('name' => 'evolvephp/http', 'directory' => 'packages/http'),
        array('name' => 'evolvephp/testing', 'directory' => 'packages/testing'),
        array('name' => 'evolvephp/dev-tools', 'directory' => 'packages/dev-tools'),
    );

    $packages = array();
    $seenNames = array();
    $seenDirectories = array();

    foreach ($map['packages'] as $index => $package) {
        if (!is_array($package) || array_keys($package) !== array('name', 'directory')) {
            releaseValidationFail('release-packages.json package entry ' . ($index + 1) . ' must contain only name and directory.');
        }

        if (!is_string($package['name']) || !is_string($package['directory'])) {
            releaseValidationFail('release-packages.json package entry ' . ($index + 1) . ' must contain string name and directory.');
        }

        $directory = normalizeRelativePath($package['directory']);

        if (preg_match('/^(?:[A-Za-z]:)?[\/\\\\]/', $package['directory']) === 1 || in_array('..', explode('/', $directory), true)) {
            releaseValidationFail($package['name'] . ' uses an unsafe package directory.');
        }

        if (isset($seenNames[$package['name']]) || isset($seenDirectories[$directory])) {
            releaseValidationFail('release-packages.json contains duplicate package names or directories.');
        }

        if (!is_file(joinPaths($root, $directory . '/composer.json'))) {
            releaseValidationFail($package['name'] . ' mapped directory must contain composer.json.');
        }

        $seenNames[$package['name']] = true;
        $seenDirectories[$directory] = true;
        $packages[] = array('name' => $package['name'], 'directory' => $directory);
    }

    if ($packages !== $expected) {
        releaseValidationFail('release-packages.json must use the canonical Phase 2.10A package order.');
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
        'refs' => trim($runner->mustRun(array(
            'git',
            '-C',
            $root,
            'for-each-ref',
            '--sort=refname',
            '--format=%(objectname) %(refname)',
            'refs/heads',
            'refs/tags',
        ))->stdout),
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
