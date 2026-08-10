<?php declare(strict_types=1);

require_once __DIR__ . '/release-validation-common.php';

final class PrereleaseConsumerValidator
{
    private const ALPHA_VERSION = '2.0.0-alpha.1';
    private const STABLE_VERSION = '2.0.0';

    private ReleaseValidationProcessRunner $runner;

    public function __construct()
    {
        $this->runner = new ReleaseValidationProcessRunner();
    }

    /**
     * @return list<array{name: string, outcome: string, detail: string}>
     */
    public function validate(string $root, string $ref, ?string $composer): array
    {
        $packages = loadReleasePackages($root);
        $sourceState = captureSourceState($this->runner, $root);
        $temp = createTemporaryDirectory();

        try {
            $sourceSha = trim($this->runner->mustRun(array('git', '-C', $root, 'rev-parse', $ref))->stdout);
            $clone = $temp->child('monorepo');

            $this->runner->mustRun(array('git', 'clone', '--no-hardlinks', $root, $clone));
            $this->runner->mustRun(array('git', '-C', $clone, 'checkout', '--detach', $sourceSha));

            $splitRoots = $this->materializeSplitRoots($temp, $clone, $sourceSha, $packages);
            $alphaRepositories = $this->createTaggedRepositories($temp->child('alpha-repositories'), $splitRoots, self::ALPHA_VERSION);
            $stableRepositories = $this->createTaggedRepositories($temp->child('stable-repositories'), $splitRoots, self::STABLE_VERSION);

            $results = array();
            $results[] = $this->runExpectedFailureCase($temp, $composer, 'Alpha case A', $alphaRepositories, array('evolvephp/http' => '2.0.0-alpha.1'));
            $results[] = $this->runExpectedFailureCase($temp, $composer, 'Alpha case B', $alphaRepositories, array('evolvephp/http' => '^2.0@alpha'));
            $results[] = $this->runExpectedSuccessCase($temp, $composer, 'Alpha case C', $alphaRepositories, array('evolvephp/http' => '^2.0'), array('minimum-stability' => 'alpha', 'prefer-stable' => true), array(
                'evolvephp/contracts' => self::ALPHA_VERSION,
                'evolvephp/core' => self::ALPHA_VERSION,
                'evolvephp/http' => self::ALPHA_VERSION,
            ));
            $results[] = $this->runExpectedSuccessCase($temp, $composer, 'Alpha case D', $alphaRepositories, array(
                'evolvephp/contracts' => '^2.0@alpha',
                'evolvephp/core' => '^2.0@alpha',
                'evolvephp/http' => '^2.0@alpha',
            ), array(), array(
                'evolvephp/contracts' => self::ALPHA_VERSION,
                'evolvephp/core' => self::ALPHA_VERSION,
                'evolvephp/http' => self::ALPHA_VERSION,
            ));
            $results[] = $this->runExpectedFailureCase($temp, $composer, 'Full-graph case E', $alphaRepositories, array('evolvephp/testing' => '^2.0@alpha'));
            $results[] = $this->runExpectedSuccessCase($temp, $composer, 'Full-graph case F', $alphaRepositories, array('evolvephp/testing' => '^2.0'), array('minimum-stability' => 'alpha', 'prefer-stable' => true), $this->expectedVersions($packages, self::ALPHA_VERSION));
            $results[] = $this->runExpectedSuccessCase($temp, $composer, 'Full-graph case G', $alphaRepositories, $this->explicitAlphaRootRequirements($packages), array(), $this->expectedVersions($packages, self::ALPHA_VERSION));
            $results[] = $this->runExpectedSuccessCase($temp, $composer, 'Stable case H', $stableRepositories, array('evolvephp/testing' => '^2.0'), array(), $this->expectedVersions($packages, self::STABLE_VERSION));

            assertSourceStatePreserved($this->runner, $root, $sourceState);

            return $results;
        } finally {
            $temp->cleanup();
        }
    }

    /**
     * @param list<array{name: string, directory: string}> $packages
     * @return array<string, string>
     */
    private function materializeSplitRoots(ReleaseValidationTemporaryDirectory $temp, string $clone, string $sourceSha, array $packages): array
    {
        $roots = array();

        foreach ($packages as $package) {
            $slug = packageSlug($package['name']);
            $split = trim($this->runner->mustRun(array('git', '-C', $clone, 'subtree', 'split', '--prefix=' . $package['directory'], $sourceSha))->stdout);
            $root = $temp->child('split-roots/' . $slug);

            $this->runner->mustRun(array('git', 'clone', '--no-hardlinks', $clone, $root));
            $this->runner->mustRun(array('git', '-C', $root, 'checkout', '--detach', $split));

            $roots[$package['name']] = $root;
        }

        return $roots;
    }

    /**
     * @param array<string, string> $splitRoots
     * @return array<string, string>
     */
    private function createTaggedRepositories(string $repositoryRoot, array $splitRoots, string $tag): array
    {
        if (!mkdir($repositoryRoot, 0777, true) && !is_dir($repositoryRoot)) {
            releaseValidationFail('Unable to create temporary package repository root: ' . $repositoryRoot);
        }

        $repositories = array();

        foreach ($splitRoots as $packageName => $splitRoot) {
            $repository = joinPaths($repositoryRoot, packageSlug($packageName));

            $this->runner->mustRun(array('git', 'clone', '--no-hardlinks', $splitRoot, $repository));
            $this->runner->mustRun(array('git', '-C', $repository, 'tag', $tag));
            $this->runner->mustRun(array('git', '-C', $repository, 'tag', '--list', $tag));

            $repositories[$packageName] = $repository;
        }

        return $repositories;
    }

    /**
     * @param array<string, string> $repositories
     * @param array<string, string> $requirements
     * @return array{name: string, outcome: string, detail: string}
     */
    private function runExpectedFailureCase(ReleaseValidationTemporaryDirectory $temp, ?string $composer, string $caseName, array $repositories, array $requirements): array
    {
        $consumer = $this->createConsumer($temp, $caseName, $repositories, $requirements, array());
        $result = $this->runComposerUpdate($consumer, $temp, $composer);
        $output = $result->output();

        if ($result->exitCode === 0) {
            releaseValidationFail($caseName . ' unexpectedly succeeded.');
        }

        if (!$this->isMinimumStabilityFailure($output)) {
            releaseValidationFail($caseName . ' failed, but not as a transitive minimum-stability rejection: ' . $output);
        }

        return array('name' => $caseName, 'outcome' => 'expected failure', 'detail' => 'transitive minimum-stability rejection');
    }

    /**
     * @param array<string, string> $repositories
     * @param array<string, string> $requirements
     * @param array<string, string|bool> $consumerOptions
     * @param array<string, string> $expectedVersions
     * @return array{name: string, outcome: string, detail: string}
     */
    private function runExpectedSuccessCase(ReleaseValidationTemporaryDirectory $temp, ?string $composer, string $caseName, array $repositories, array $requirements, array $consumerOptions, array $expectedVersions): array
    {
        $consumer = $this->createConsumer($temp, $caseName, $repositories, $requirements, $consumerOptions);
        $result = $this->runComposerUpdate($consumer, $temp, $composer);

        if ($result->exitCode !== 0) {
            releaseValidationFail($caseName . ' failed unexpectedly: ' . $result->output());
        }

        $actualVersions = $this->readLockedFirstPartyVersions(joinPaths($consumer, 'composer.lock'));

        if ($actualVersions !== $expectedVersions) {
            releaseValidationFail($caseName . ' selected unexpected package versions. expected ' . json_encode($expectedVersions) . ', actual ' . json_encode($actualVersions) . '.');
        }

        return array('name' => $caseName, 'outcome' => 'success', 'detail' => $this->formatVersions($actualVersions));
    }

    /**
     * @param array<string, string> $repositories
     * @param array<string, string> $requirements
     * @param array<string, string|bool> $consumerOptions
     */
    private function createConsumer(ReleaseValidationTemporaryDirectory $temp, string $caseName, array $repositories, array $requirements, array $consumerOptions): string
    {
        $directory = $temp->child('consumers/' . strtolower(str_replace(' ', '-', $caseName)));

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            releaseValidationFail('Unable to create consumer fixture for ' . $caseName . '.');
        }

        $repositoryEntries = array(array('packagist.org' => false));

        foreach ($repositories as $repository) {
            $repositoryEntries[] = array('type' => 'vcs', 'url' => normalizePath($repository));
        }

        $manifest = array(
            'name' => 'evolvephp/' . strtolower(str_replace(' ', '-', $caseName)),
            'type' => 'project',
            'repositories' => $repositoryEntries,
            'require' => $requirements,
            'config' => array('allow-plugins' => false, 'secure-http' => false),
        );

        foreach ($consumerOptions as $key => $value) {
            $manifest[$key] = $value;
        }

        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded) || file_put_contents(joinPaths($directory, 'composer.json'), $encoded . PHP_EOL) === false) {
            releaseValidationFail('Unable to write disposable consumer fixture for ' . $caseName . '.');
        }

        return $directory;
    }

    private function runComposerUpdate(string $consumer, ReleaseValidationTemporaryDirectory $temp, ?string $composer): ReleaseValidationProcessResult
    {
        return $this->runner->run(
            withComposerCommand(array(
                '--working-dir=' . $consumer,
                'update',
                '--no-interaction',
                '--no-plugins',
                '--no-scripts',
                '--no-audit',
                '--no-install',
                '--no-ansi',
            ), $composer),
            null,
            array(
                'COMPOSER_DISABLE_NETWORK' => '1',
                'COMPOSER_HOME' => $temp->child('composer-home'),
                'COMPOSER_CACHE_DIR' => $temp->child('composer-cache'),
            )
        );
    }

    private function isMinimumStabilityFailure(string $output): bool
    {
        return str_contains($output, 'minimum-stability')
            && str_contains($output, 'found evolvephp/')
            && str_contains($output, 'but it does not match');
    }

    /**
     * @return array<string, string>
     */
    private function readLockedFirstPartyVersions(string $lockfile): array
    {
        $lock = readJsonFile($lockfile, 'disposable composer.lock');

        if (!is_array($lock) || !isset($lock['packages']) || !is_array($lock['packages'])) {
            releaseValidationFail('Disposable composer.lock must contain packages.');
        }

        $versions = array();

        foreach ($lock['packages'] as $package) {
            if (!is_array($package) || !isset($package['name'], $package['version'])) {
                continue;
            }

            if (str_starts_with((string) $package['name'], 'evolvephp/')) {
                $versions[(string) $package['name']] = (string) $package['version'];
            }
        }

        ksort($versions);

        return $versions;
    }

    /**
     * @param list<array{name: string, directory: string}> $packages
     * @return array<string, string>
     */
    private function expectedVersions(array $packages, string $version): array
    {
        $versions = array();

        foreach ($packages as $package) {
            $versions[$package['name']] = $version;
        }

        ksort($versions);

        return $versions;
    }

    /**
     * @param list<array{name: string, directory: string}> $packages
     * @return array<string, string>
     */
    private function explicitAlphaRootRequirements(array $packages): array
    {
        $requirements = array();

        foreach ($packages as $package) {
            $requirements[$package['name']] = '^2.0@alpha';
        }

        return $requirements;
    }

    /**
     * @param array<string, string> $versions
     */
    private function formatVersions(array $versions): string
    {
        $lines = array();

        foreach ($versions as $name => $version) {
            $lines[] = $name . ' ' . $version;
        }

        return implode(', ', $lines);
    }
}

try {
    $options = parseReleaseValidationArguments($argv);
    $validator = new PrereleaseConsumerValidator();
    $results = $validator->validate($options['root'], $options['ref'], $options['composer']);

    echo 'EvolvePHP prerelease consumer validation passed.' . PHP_EOL;
    echo 'COMPOSER_DISABLE_NETWORK: enabled' . PHP_EOL;
    echo 'Packagist: disabled' . PHP_EOL;
    echo 'Alpha tag: 2.0.0-alpha.1' . PHP_EOL;
    echo 'Stable tag: 2.0.0' . PHP_EOL;

    foreach ($results as $result) {
        echo $result['name'] . ': ' . $result['outcome'] . ' - ' . $result['detail'] . PHP_EOL;
    }

    echo '^2.0 remains correct.' . PHP_EOL;
    echo 'Default stability alpha failures are expected minimum-stability rejections.' . PHP_EOL;
    echo 'Top-level @alpha alone is insufficient for transitive alpha packages.' . PHP_EOL;
    echo 'minimum-stability: alpha plus prefer-stable: true succeeds.' . PHP_EOL;
    echo 'Explicit root @alpha flags succeed.' . PHP_EOL;
    echo 'Stable 2.0.0 succeeds under default stability.' . PHP_EOL;
    echo 'No package-manifest change is required.' . PHP_EOL;
    echo 'Source repository state preserved.' . PHP_EOL;
    exit(0);
} catch (ReleaseValidationFailure $failure) {
    fwrite(STDERR, 'EvolvePHP prerelease consumer validation failed: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
