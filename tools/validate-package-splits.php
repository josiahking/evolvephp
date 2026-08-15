<?php declare(strict_types=1);

require_once __DIR__ . '/release-validation-common.php';

/**
 * This validator intentionally exercises:
 * supported options: --root=, --ref=, --composer=.
 * git subtree split, first split, second split, deterministic: yes,
 * tree equality: yes, inventory equality: yes, composer validate --strict: pass,
 * history commits:, Source repository state preserved.
 */
final class PackageSplitValidator
{
    private ReleaseValidationProcessRunner $runner;

    public function __construct()
    {
        $this->runner = new ReleaseValidationProcessRunner();
    }

    /**
     * @return list<array<string, string|int>>
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

            $results = array();

            foreach ($packages as $package) {
                $results[] = $this->validatePackage($temp, $clone, $sourceSha, $package, $composer);
            }

            assertSourceStatePreserved($this->runner, $root, $sourceState);

            return $results;
        } finally {
            $temp->cleanup();
        }
    }

    /**
     * @param array{name: string, directory: string} $package
     * @return array<string, string|int>
     */
    private function validatePackage(ReleaseValidationTemporaryDirectory $temp, string $clone, string $sourceSha, array $package, ?string $composer): array
    {
        $name = $package['name'];
        $directory = $package['directory'];
        $slug = packageSlug($name);

        $firstSplit = trim($this->runner->mustRun(array('git', '-C', $clone, 'subtree', 'split', '--prefix=' . $directory, $sourceSha))->stdout);
        $secondSplit = trim($this->runner->mustRun(array('git', '-C', $clone, 'subtree', 'split', '--prefix=' . $directory, $sourceSha))->stdout);

        if ($firstSplit !== $secondSplit) {
            releaseValidationFail($name . ' split is not deterministic. expected ' . $firstSplit . ', actual ' . $secondSplit . '.');
        }

        $originalTree = trim($this->runner->mustRun(array('git', '-C', $clone, 'rev-parse', $sourceSha . ':' . $directory))->stdout);
        $splitTree = trim($this->runner->mustRun(array('git', '-C', $clone, 'rev-parse', $firstSplit . '^{tree}'))->stdout);

        if ($originalTree !== $splitTree) {
            releaseValidationFail($name . ' split root tree mismatch. expected ' . $originalTree . ', actual ' . $splitTree . '.');
        }

        $originalInventory = sortedLines(str_replace($directory . '/', '', $this->runner->mustRun(array('git', '-C', $clone, 'ls-tree', '-r', '--name-only', $sourceSha, $directory))->stdout));
        $splitInventory = sortedLines($this->runner->mustRun(array('git', '-C', $clone, 'ls-tree', '-r', '--name-only', $firstSplit))->stdout);

        if ($originalInventory !== $splitInventory) {
            releaseValidationFail($name . ' split inventory mismatch.');
        }

        $splitRoot = $temp->child('split-roots/' . $slug);
        $this->runner->mustRun(array('git', 'clone', '--no-hardlinks', $clone, $splitRoot));
        $this->runner->mustRun(array('git', '-C', $splitRoot, 'checkout', '--detach', $firstSplit));

        $this->validateSplitRoot($splitRoot, $package, $composer);

        $historyCount = (int) trim($this->runner->mustRun(array('git', '-C', $clone, 'rev-list', '--count', $firstSplit))->stdout);

        return array(
            'name' => $name,
            'directory' => $directory,
            'firstSplit' => $firstSplit,
            'secondSplit' => $secondSplit,
            'originalTree' => $originalTree,
            'splitTree' => $splitTree,
            'fileCount' => count($splitInventory),
            'historyCount' => $historyCount,
        );
    }

    /**
     * @param array{name: string, directory: string} $package
     */
    private function validateSplitRoot(string $splitRoot, array $package, ?string $composer): void
    {
        foreach (array('composer.json', 'README.md', 'LICENSE.md', 'src', 'tests') as $requiredPath) {
            if (!file_exists(joinPaths($splitRoot, $requiredPath))) {
                releaseValidationFail($package['name'] . ' split root is missing ' . $requiredPath . '.');
            }
        }

        $forbidden = array('packages', 'workspace', 'docs', '.github', 'CHANGELOG.md');

        foreach ($forbidden as $path) {
            if (file_exists(joinPaths($splitRoot, $path))) {
                releaseValidationFail($package['name'] . ' split root unexpectedly contains monorepo-owned ' . $path . '.');
            }
        }

        $this->runner->mustRun(withComposerCommand(array('--working-dir=' . $splitRoot, 'validate', '--strict'), $composer));

        $manifest = readJsonFile(joinPaths($splitRoot, 'composer.json'), $package['name'] . ' generated split composer.json');

        if (!is_array($manifest)) {
            releaseValidationFail($package['name'] . ' generated split composer.json must be a JSON object.');
        }

        if (($manifest['name'] ?? null) !== $package['name']) {
            releaseValidationFail($package['name'] . ' split manifest name changed.');
        }

        if (($manifest['license'] ?? null) !== 'BSD-3-Clause') {
            releaseValidationFail($package['name'] . ' split manifest license changed.');
        }

        if (($manifest['require']['php'] ?? null) !== '^8.4') {
            releaseValidationFail($package['name'] . ' split manifest PHP constraint changed.');
        }

        if (array_key_exists('version', $manifest)) {
            releaseValidationFail($package['name'] . ' split manifest must not contain a hard-coded version.');
        }

        foreach (($manifest['require'] ?? array()) as $dependency => $constraint) {
            if (str_starts_with((string) $dependency, 'evolvephp/') && $constraint !== '^2.0') {
                releaseValidationFail($package['name'] . ' split manifest internal constraint changed for ' . $dependency . '.');
            }
        }
    }
}

try {
    $options = parseReleaseValidationArguments($argv);
    $validator = new PackageSplitValidator();
    $results = $validator->validate($options['root'], $options['ref'], $options['composer']);
    $source = trim((new ReleaseValidationProcessRunner())->mustRun(array('git', '-C', $options['root'], 'rev-parse', $options['ref']))->stdout);

    echo 'EvolvePHP package split validation passed.' . PHP_EOL;
    echo 'Source: ' . $source . PHP_EOL;
    echo 'Packages: ' . count($results) . PHP_EOL . PHP_EOL;

    foreach ($results as $result) {
        echo $result['name'] . PHP_EOL;
        echo '  first split: ' . $result['firstSplit'] . PHP_EOL;
        echo '  second split: ' . $result['secondSplit'] . PHP_EOL;
        echo '  deterministic: yes' . PHP_EOL;
        echo '  tree equality: yes' . PHP_EOL;
        echo '  inventory equality: yes' . PHP_EOL;
        echo '  file count: ' . $result['fileCount'] . PHP_EOL;
        echo '  composer validate --strict: pass' . PHP_EOL;
        echo '  history commits: ' . $result['historyCount'] . PHP_EOL;
    }

    echo 'Source repository state preserved.' . PHP_EOL;
    exit(0);
} catch (ReleaseValidationFailure $failure) {
    fwrite(STDERR, 'EvolvePHP package split validation failed: ' . $failure->getMessage() . PHP_EOL);
    exit(1);
}
