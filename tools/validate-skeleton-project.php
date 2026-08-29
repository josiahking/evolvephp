<?php declare(strict_types=1);

require_once __DIR__ . '/release-validation-common.php';

final class SkeletonProjectValidator
{
    private const ALPHA_VERSION = '2.0.0-alpha.1';

    private ReleaseValidationProcessRunner $runner;

    public function __construct()
    {
        $this->runner = new ReleaseValidationProcessRunner();
    }

    public function validate(string $root, ?string $composer): void
    {
        $sourceState = captureSourceState($this->runner, $root);
        $temp = createTemporaryDirectory('evolvephp-skeleton-validation-');
        $failure = null;

        try {
            $this->validateInTemporaryProject($root, $temp, $composer);
        } catch (ReleaseValidationFailure $exception) {
            $failure = $exception;
        } finally {
            try {
                $this->stage('[13/13] Cleaning up and preserving source state');
                $temp->cleanup();
                assertSourceStatePreserved($this->runner, $root, $sourceState);
            } catch (ReleaseValidationFailure $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    private function validateInTemporaryProject(string $root, ReleaseValidationTemporaryDirectory $temp, ?string $composer): void
    {
        $application = $temp->child('application');

        if (file_exists($application)) {
            releaseValidationFail('Temporary application target already exists before create-project.');
        }

        $this->stage('[1/13] Preparing offline repositories');
        $lockedVendorPackages = loadSkeletonLockedPackageRepositoryPackages($root);
        $offlineVendorRepository = $this->prepareOfflineVendorRepository($root, $temp, $lockedVendorPackages);

        $this->stage('[2/13] Running Composer create-project');
        $this->runner->mustRun(
            withComposerCommand(array(
                'create-project',
                '--no-interaction',
                '--no-progress',
                '--no-ansi',
                '--no-scripts',
                '--no-audit',
                '--add-repository',
                '--repository=' . json_encode(array('packagist.org' => false), JSON_THROW_ON_ERROR),
                '--repository=' . json_encode($this->skeletonRepository($root), JSON_THROW_ON_ERROR),
                '--repository=' . json_encode($this->frameworkRepository($root), JSON_THROW_ON_ERROR),
                '--repository=' . json_encode($this->vendorRepository($offlineVendorRepository, $lockedVendorPackages), JSON_THROW_ON_ERROR),
                'evolvephp/skeleton',
                $application,
                self::ALPHA_VERSION,
            ), $composer),
            null,
            $this->composerEnvironment($temp),
            '[2/13] Running Composer create-project',
        );

        if (!is_dir($application)) {
            releaseValidationFail('Composer create-project did not create the application directory.');
        }

        $this->stage('[3/13] Validating generated manifest');
        $this->restoreDistributedManifest($root, $application);
        $this->validateGeneratedManifest($application);
        $this->runner->mustRun(
            withComposerCommand(array('--working-dir=' . $application, 'validate', '--strict', '--no-check-lock', '--no-ansi'), $composer),
            null,
            $this->composerEnvironment($temp),
            '[3/13] Validating generated manifest',
        );

        $this->stage('[4/13] Validating installed packages');
        $this->validateInstalledPackages($root, $application, array('contracts', 'core', 'dev-tools', 'http', 'module', 'plugin', 'testing'));

        $this->stage('[5/13] Running generated Doctor');
        $this->assertCommand('[5/13] Running generated Doctor', $application, array('doctor'), 0, null, '');

        $this->stage('[6/13] Running generated route:list');
        $this->assertCommand('[6/13] Running generated route:list', $application, array('route:list'), 0, 'No routes are configured.' . PHP_EOL, '');

        $this->stage('[7/13] Running generated module:new');
        $this->assertCommand('[7/13] Running generated module:new', $application, array('module:new', 'Billing'), 0, implode(PHP_EOL, array(
            'Created module app/billing.',
            'src/Modules/Billing/BillingModule.php',
            'src/Modules/Billing/module.php',
            'tests/Modules/Billing/BillingModuleTest.php',
            '',
        )), '');

        $this->stage('[8/13] Running generated plugin:new');
        $this->assertCommand('[8/13] Running generated plugin:new', $application, array('plugin:new', 'Cache'), 0, implode(PHP_EOL, array(
            'Created plugin app/cache.',
            'src/Plugins/Cache/CachePlugin.php',
            'src/Plugins/Cache/plugin.php',
            'tests/Plugins/Cache/CachePluginTest.php',
            '',
        )), '');

        $this->stage('[9/13] Running generated test suite');
        $this->validateGeneratedStarterFiles($application);
        $this->runner->mustRun(
            array(PHP_BINARY, joinPaths($application, 'vendor/bin/phpunit'), '--configuration', 'phpunit.xml.dist'),
            $application,
            $this->composerEnvironment($temp),
            '[9/13] Running generated test suite',
        );

        $this->stage('[10/13] Running collision and traversal checks');
        $this->assertCommand('[10/13] Running collision and traversal checks', $application, array('module:new', 'Billing'), 1, '', 'Refusing to overwrite existing file: src/Modules/Billing/BillingModule.php' . PHP_EOL);
        $this->assertCommand('[10/13] Running collision and traversal checks', $application, array('plugin:new', 'Cache'), 1, '', 'Refusing to overwrite existing file: src/Plugins/Cache/CachePlugin.php' . PHP_EOL);
        $this->assertCommand('[10/13] Running collision and traversal checks', $application, array('module:new', '../Escape'), 2, '', 'Usage: module:new <StudlyName>' . PHP_EOL);
        $this->assertCommand('[10/13] Running collision and traversal checks', $application, array('plugin:new', '..\\Escape'), 2, '', 'Usage: plugin:new <StudlyName>' . PHP_EOL);
        $this->assertNoGeneratedFileOutsideApplication($temp, $application);
        $this->validateProductionInstall($root, $application, $temp, $composer);
        $this->assertCommand('[10/13] Running collision and traversal checks', $application, array(), 2, '', 'No command was specified.' . PHP_EOL);
        $this->assertCommand('[10/13] Running collision and traversal checks', $application, array('missing'), 2, '', 'Command "missing" was not found.' . PHP_EOL);
        $this->assertCommand('[10/13] Running collision and traversal checks', $application, array('route:list', '--json'), 2, '', 'The route:list command does not accept arguments or options.' . PHP_EOL);
    }

    private function restoreDistributedManifest(string $root, string $application): void
    {
        $sourceManifest = file_get_contents(joinPaths($root, 'skeleton/composer.json'));

        if ($sourceManifest === false) {
            releaseValidationFail('Unable to read distributed skeleton composer.json.');
        }

        if (file_put_contents(joinPaths($application, 'composer.json'), $sourceManifest) === false) {
            releaseValidationFail('Unable to restore generated skeleton composer.json after repository injection.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function skeletonRepository(string $root): array
    {
        return array(
            'type' => 'path',
            'url' => normalizePath(joinPaths($root, 'skeleton')),
            'options' => array(
                'symlink' => false,
                'versions' => array(
                    'evolvephp/skeleton' => self::ALPHA_VERSION,
                ),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function frameworkRepository(string $root): array
    {
        return array(
            'type' => 'path',
            'url' => normalizePath(joinPaths($root, 'packages/*')),
            'options' => array(
                'symlink' => false,
                'versions' => $this->frameworkVersions($root),
            ),
        );
    }

    /**
     * @param list<array<string, mixed>> $packages
     * @return array<string, mixed>
     */
    private function vendorRepository(string $repositoryRoot, array $packages): array
    {
        $versions = $this->lockedPackageRepositoryVersions($packages);

        return array(
            'type' => 'path',
            'url' => normalizePath(joinPaths($repositoryRoot, '*/*')),
            'only' => array_keys($versions),
            'options' => array(
                'symlink' => false,
                'versions' => $versions,
            ),
        );
    }

    /**
     * @return array<string, string>
     */
    private function frameworkVersions(string $root): array
    {
        $versions = array();

        foreach (loadReleasePackages($root) as $package) {
            $versions[$package['name']] = self::ALPHA_VERSION;
        }

        ksort($versions);

        return $versions;
    }

    /**
     * @param list<array<string, mixed>> $packages
     * @return array<string, string>
     */
    private function lockedPackageRepositoryVersions(array $packages): array
    {
        $versions = array();

        foreach ($packages as $package) {
            $versions[$package['name']] = $package['version'];
        }

        ksort($versions);

        return $versions;
    }

    /**
     * @param list<array<string, mixed>> $packages
     */
    private function prepareOfflineVendorRepository(string $root, ReleaseValidationTemporaryDirectory $temp, array $packages): string
    {
        $repositoryRoot = $temp->child('offline-vendor');

        if (!mkdir($repositoryRoot, 0777, true) && !is_dir($repositoryRoot)) {
            releaseValidationFail('Unable to create offline vendor repository: ' . $repositoryRoot);
        }

        foreach ($packages as $package) {
            $source = joinPaths($root, 'vendor/' . $package['name']);
            $target = joinPaths($repositoryRoot, $package['name']);

            if (!is_dir($source)) {
                releaseValidationFail('Locked package is not installed locally for offline skeleton validation: ' . $package['name']);
            }

            $this->copyDirectory($source, $target);
        }

        return $repositoryRoot;
    }

    private function copyDirectory(string $source, string $target): void
    {
        $sourceRealPath = realpath($source);

        if ($sourceRealPath === false || !is_dir($sourceRealPath)) {
            releaseValidationFail('Unable to resolve offline repository source: ' . $source);
        }

        if (!mkdir($target, 0777, true) && !is_dir($target)) {
            releaseValidationFail('Unable to create offline repository target: ' . $target);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRealPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $entry) {
            $relativePath = substr($entry->getPathname(), strlen($sourceRealPath) + 1);
            $targetPath = $target . DIRECTORY_SEPARATOR . $relativePath;

            if ($entry->isLink()) {
                releaseValidationFail('Refusing to copy symlink into offline repository: ' . $entry->getPathname());
            }

            if ($entry->isDir()) {
                if (!mkdir($targetPath, 0777, true) && !is_dir($targetPath)) {
                    releaseValidationFail('Unable to create offline repository directory: ' . $targetPath);
                }

                continue;
            }

            if (!copy($entry->getPathname(), $targetPath)) {
                releaseValidationFail('Unable to copy offline repository file: ' . $entry->getPathname());
            }
        }
    }

    private function validateGeneratedManifest(string $application): void
    {
        $manifest = readJsonFile(joinPaths($application, 'composer.json'), 'generated skeleton composer.json');

        if (!is_array($manifest)) {
            releaseValidationFail('Generated skeleton composer.json must decode to an object.');
        }

        if (($manifest['name'] ?? null) !== 'evolvephp/skeleton') {
            releaseValidationFail('Generated skeleton manifest package name changed.');
        }

        if (($manifest['type'] ?? null) !== 'project') {
            releaseValidationFail('Generated skeleton manifest type changed.');
        }

        if (($manifest['require']['php'] ?? null) !== '^8.4') {
            releaseValidationFail('Generated skeleton manifest PHP constraint changed.');
        }

        if (($manifest['require']['evolvephp/contracts'] ?? null) !== '^2.0') {
            releaseValidationFail('Generated skeleton manifest Contracts dependency changed.');
        }

        if (($manifest['require']['evolvephp/core'] ?? null) !== '^2.0') {
            releaseValidationFail('Generated skeleton manifest Core dependency changed.');
        }

        if (($manifest['require']['evolvephp/http'] ?? null) !== '^2.0') {
            releaseValidationFail('Generated skeleton manifest HTTP dependency changed.');
        }

        if (($manifest['require']['evolvephp/module'] ?? null) !== '^2.0') {
            releaseValidationFail('Generated skeleton manifest Module dependency changed.');
        }

        if (($manifest['require']['evolvephp/plugin'] ?? null) !== '^2.0') {
            releaseValidationFail('Generated skeleton manifest Plugin dependency changed.');
        }

        if (($manifest['require-dev']['evolvephp/dev-tools'] ?? null) !== '^2.0') {
            releaseValidationFail('Generated skeleton manifest DevTools development dependency changed.');
        }

        if (($manifest['require-dev']['evolvephp/testing'] ?? null) !== '^2.0') {
            releaseValidationFail('Generated skeleton manifest Testing development dependency changed.');
        }

        if (($manifest['require-dev']['phpunit/phpunit'] ?? null) !== '^13.2') {
            releaseValidationFail('Generated skeleton manifest PHPUnit development dependency changed.');
        }

        if (($manifest['autoload']['psr-4']['App\\'] ?? null) !== 'src/') {
            releaseValidationFail('Generated skeleton manifest App namespace mapping changed.');
        }

        if (($manifest['autoload-dev']['psr-4']['Tests\\'] ?? null) !== 'tests/') {
            releaseValidationFail('Generated skeleton manifest Tests namespace mapping changed.');
        }

        if (($manifest['scripts']['test'] ?? null) !== 'phpunit --configuration phpunit.xml.dist') {
            releaseValidationFail('Generated skeleton manifest test script changed.');
        }

        if (($manifest['minimum-stability'] ?? null) !== 'alpha' || ($manifest['prefer-stable'] ?? null) !== true) {
            releaseValidationFail('Generated skeleton manifest prerelease stability policy changed.');
        }

        foreach (array('version', 'repositories') as $field) {
            if (array_key_exists($field, $manifest)) {
                releaseValidationFail('Generated skeleton manifest contains forbidden field: ' . $field);
            }
        }
    }

    /**
     * @param list<string> $packages
     */
    private function validateInstalledPackages(string $root, string $application, array $packages): void
    {
        foreach ($packages as $package) {
            $installedPath = joinPaths($application, 'vendor/evolvephp/' . $package);

            if (!is_dir($installedPath)) {
                releaseValidationFail('Generated project did not install evolvephp/' . $package . '.');
            }

            if (is_link($installedPath)) {
                releaseValidationFail('Generated project installed evolvephp/' . $package . ' as a symlink.');
            }

            $installedRealPath = realpath($installedPath);
            $sourceRealPath = realpath(joinPaths($root, 'packages/' . $package));

            if ($installedRealPath === false || $sourceRealPath === false) {
                releaseValidationFail('Unable to resolve installed package path for evolvephp/' . $package . '.');
            }

            if (normalizePath($installedRealPath) === normalizePath($sourceRealPath)
                || str_starts_with(normalizePath($installedRealPath), normalizePath($sourceRealPath) . '/')
            ) {
                releaseValidationFail('Generated project installed evolvephp/' . $package . ' from the source monorepo path.');
            }
        }
    }

    private function validateGeneratedStarterFiles(string $application): void
    {
        foreach (array(
            'src/Modules/Billing/BillingModule.php',
            'src/Modules/Billing/module.php',
            'tests/Modules/Billing/BillingModuleTest.php',
            'src/Plugins/Cache/CachePlugin.php',
            'src/Plugins/Cache/plugin.php',
            'tests/Plugins/Cache/CachePluginTest.php',
        ) as $relativePath) {
            $path = joinPaths($application, $relativePath);

            if (!is_file($path)) {
                releaseValidationFail('Generated starter file is missing: ' . $relativePath);
            }

            $realPath = realpath($path);
            $realApplication = realpath($application);

            if ($realPath === false || $realApplication === false || !str_starts_with($realPath, $realApplication . DIRECTORY_SEPARATOR)) {
                releaseValidationFail('Generated starter file escaped the application root: ' . $relativePath);
            }
        }
    }

    private function assertNoGeneratedFileOutsideApplication(ReleaseValidationTemporaryDirectory $temp, string $application): void
    {
        $applicationRealPath = realpath($application);

        if ($applicationRealPath === false) {
            releaseValidationFail('Unable to resolve generated application path.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temp->path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if (!$entry->isFile()) {
                continue;
            }

            $realPath = realpath($entry->getPathname());

            if ($realPath === false || str_starts_with($realPath, $applicationRealPath . DIRECTORY_SEPARATOR)) {
                continue;
            }

            if (str_contains(normalizePath($realPath), '/Escape')) {
                releaseValidationFail('Invalid generator input created a file outside the application root.');
            }
        }
    }

    private function validateProductionInstall(string $root, string $application, ReleaseValidationTemporaryDirectory $temp, ?string $composer): void
    {
        $this->stage('[11/13] Running Composer install --no-dev');
        removeDirectory(joinPaths($application, 'vendor'), $application);

        $this->runner->mustRun(
            withComposerCommand(array(
                '--working-dir=' . $application,
                'install',
                '--no-dev',
                '--no-interaction',
                '--no-progress',
                '--no-ansi',
                '--no-scripts',
            ), $composer),
            null,
            $this->composerEnvironment($temp),
            '[11/13] Running Composer install --no-dev',
        );

        $this->validateInstalledPackages($root, $application, array('contracts', 'core', 'http', 'module', 'plugin'));
        $this->assertPackageNotInstalled($application, 'dev-tools');
        $this->assertPackageNotInstalled($application, 'testing');

        $this->stage('[12/13] Running no-dev Doctor and route:list');
        $this->assertCommand('[12/13] Running no-dev Doctor and route:list', $application, array('doctor'), 0, null, '');
        $this->assertCommand('[12/13] Running no-dev Doctor and route:list', $application, array('route:list'), 0, 'No routes are configured.' . PHP_EOL, '');
    }

    private function assertPackageNotInstalled(string $application, string $package): void
    {
        $installedPath = joinPaths($application, 'vendor/evolvephp/' . $package);

        if (file_exists($installedPath)) {
            releaseValidationFail('Production no-dev install unexpectedly installed evolvephp/' . $package . '.');
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function assertCommand(
        string $stage,
        string $application,
        array $arguments,
        int $expectedExitCode,
        ?string $expectedStdout,
        string $expectedStderr,
    ): void {
        $result = $this->runner->run(array(PHP_BINARY, 'bin/evolve', ...$arguments), $application, array(), $stage);

        if ($result->exitCode !== $expectedExitCode) {
            releaseValidationFail('Generated command failed with unexpected exit code during ' . $stage . ': ' . describeCommand($result->command) . "\n" . $result->output());
        }

        if ($expectedStdout !== null && $result->stdout !== $expectedStdout) {
            releaseValidationFail('Generated command stdout mismatch during ' . $stage . ' for ' . describeCommand($result->command) . '.');
        }

        if ($result->stderr !== $expectedStderr) {
            releaseValidationFail('Generated command stderr mismatch during ' . $stage . ' for ' . describeCommand($result->command) . '.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function composerEnvironment(ReleaseValidationTemporaryDirectory $temp): array
    {
        return array(
            'COMPOSER_DISABLE_NETWORK' => '1',
            'COMPOSER_HOME' => $temp->child('composer-home'),
            'COMPOSER_CACHE_DIR' => $temp->child('composer-cache'),
        );
    }

    private function stage(string $message): void
    {
        echo $message . PHP_EOL;
    }
}

try {
    $options = parseReleaseValidationArguments($argv, false);
    $validator = new SkeletonProjectValidator();
    $validator->validate($options['root'], $options['composer']);

    echo 'EvolvePHP skeleton create-project validation passed.' . PHP_EOL;
    echo 'Composer command: create-project' . PHP_EOL;
    echo 'COMPOSER_DISABLE_NETWORK: enabled' . PHP_EOL;
    echo 'Packagist: disabled' . PHP_EOL;
    echo 'First-party package path repositories: copied, not symlinked' . PHP_EOL;
    echo 'Generated composer validate --strict: pass' . PHP_EOL;
    echo 'Generated command doctor: pass' . PHP_EOL;
    echo 'Generated command route:list: No routes are configured.' . PHP_EOL;
    echo 'Generated command module:new Billing: pass' . PHP_EOL;
    echo 'Generated command plugin:new Cache: pass' . PHP_EOL;
    echo 'Generated application PHPUnit suite: pass' . PHP_EOL;
    echo 'Generated repeat scaffolds refuse overwrites: pass' . PHP_EOL;
    echo 'Generated invalid/path traversal names create nothing outside the application: pass' . PHP_EOL;
    echo 'Generated composer install --no-dev: pass' . PHP_EOL;
    echo 'Generated no-dev command doctor: pass' . PHP_EOL;
    echo 'Generated no-dev command route:list: No routes are configured.' . PHP_EOL;
    echo 'Generated missing-command behavior: Core usage errors preserved.' . PHP_EOL;
    echo 'Source repository state preserved.' . PHP_EOL;
    exit(0);
} catch (ReleaseValidationFailure $failure) {
    fwrite(STDERR, 'EvolvePHP skeleton create-project validation failed: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
