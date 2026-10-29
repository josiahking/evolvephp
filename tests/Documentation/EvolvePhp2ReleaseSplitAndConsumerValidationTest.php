<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2ReleaseSplitAndConsumerValidationTest extends TestCase
{
    private $root;


    public function testPublisherPreparationIsDeterministicBoundAndHasNoSideEffects(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $temporary = $this->createPlannerFixture();
        $runner = new ReleaseValidationProcessRunner();
        try {
            $before = $this->plannerFixtureState($runner, $temporary->path);
            $first = $this->runPublisher($temporary->path);
            $trace = $temporary->child('forbidden-trace.json');
            $second = $this->runPublisher($temporary->path, array(), array('GIT_TRACE2_EVENT' => $trace, 'GIT_DIR' => $temporary->child('nonexistent-git-dir'), 'GH_TOKEN' => 'secret-sentinel'));
            $this->assertFileDoesNotExist($trace);
            $this->assertSame(0, $first->exitCode, $first->output());
            $this->assertSame('', $first->stderr);
            $this->assertSame($first->stdout, $second->stdout);
            $plan = json_decode($first->stdout, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('dry-run', $plan['mode']);
            $this->assertFalse($plan['publication_enabled']);
            $this->assertSame('blocked', $plan['publication_readiness']);
            $this->assertSame('evolvephp/contracts', $plan['pilot']);
            $this->assertCount(34, $plan['packages']);
            $this->assertSame(array_column($this->releasePackages(), 'name'), array_column($plan['packages'], 'name'));
            $digest = $plan['plan_sha256'];
            unset($plan['plan_sha256']);
            $this->assertSame(hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), $digest);
            foreach ($plan['packages'] as $index => $package) {
                $tree = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD:' . $package['directory']), $temporary->path)->stdout);
                $this->assertSame($tree, $package['package_tree']);
                $this->assertSame($tree, $package['required_attestation']['split_tree']);
                $this->assertNull($package['split_commit']);
                $this->assertNull($package['verified_tag_object']);
                $this->assertSame('refs/tags/2.0.0-beta.1', $package['required_attestation']['tag_ref']);
                $this->assertSame($plan['source'], $package['required_attestation']['source']);
                $this->assertSame($index === 0 ? 'pilot-approval-required' : 'pilot-acceptance-and-later-approval-required', $package['gate']);
                foreach ($package['dependencies'] as $dependency) {
                    $this->assertContains($dependency, array_column(array_slice($plan['packages'], 0, $index), 'name'));
                }
            }
            $this->assertSame($before, $this->plannerFixtureState($runner, $temporary->path));
        } finally {
            $temporary->cleanup();
        }
    }

    public function testPublisherRejectsIdentityArgumentsCredentialsAndDirtyStates(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $temporary = $this->createPlannerFixture();
        $runner = new ReleaseValidationProcessRunner();
        try {
            foreach (array(
                array('--source-commit=' . str_repeat('0', 40)),
                array('--source-tree=' . str_repeat('0', 40)),
                array('--source-branch=2.x'), array('--source-commit=HEAD'),
                array('--publish'), array('--push'), array('--authorize=secret-sentinel'),
                array('--token=secret-sentinel'), array('--version=2.0.0'),
                array('--observations=missing', '--observations=duplicate'),
            ) as $options) {
                $before = $this->plannerFixtureState($runner, $temporary->path);
                $result = $this->runPublisher($temporary->path, $options, array('GH_TOKEN' => 'secret-sentinel', 'CI' => 'true', 'PUBLISH_AUTHORIZED' => '1'));
                $this->assertSame(1, $result->exitCode, $result->output());
                $this->assertSame('', $result->stdout);
                $this->assertStringNotContainsString('secret-sentinel', $result->output());
                $this->assertSame($before, $this->plannerFixtureState($runner, $temporary->path));
            }
            $missing = $runner->run(array(PHP_BINARY, $this->path('tools/release-package-publisher.php'), '--root=' . $temporary->path));
            $this->assertSame(1, $missing->exitCode);
            $this->assertStringContainsString('source identity', $missing->stderr);
            $withCredentials = $this->runPublisher($temporary->path, array(), array('GH_TOKEN' => 'secret-sentinel', 'CI' => 'true'));
            $this->assertSame(0, $withCredentials->exitCode, $withCredentials->output());
            $this->assertFalse(json_decode($withCredentials->stdout, true)['publication_enabled']);
            $this->assertStringNotContainsString('secret-sentinel', $withCredentials->output());
            foreach (array('untracked', 'tracked', 'index', 'hidden') as $kind) {
                $path = $kind === 'untracked' ? 'new.txt' : 'packages/contracts/README.md';
                file_put_contents($temporary->child($path), "\nchanged", FILE_APPEND);
                if ($kind === 'index') {
                    $runner->mustRun(array('git', 'add', $path), $temporary->path);
                }
                if ($kind === 'hidden') {
                    $runner->mustRun(array('git', 'update-index', '--assume-unchanged', $path), $temporary->path);
                }
                $before = $this->plannerFixtureState($runner, $temporary->path);
                $result = $this->runPublisher($temporary->path);
                $this->assertSame(1, $result->exitCode, $kind . $result->output());
                $this->assertSame($before, $this->plannerFixtureState($runner, $temporary->path));
                if ($kind === 'hidden') {
                    $runner->mustRun(array('git', 'update-index', '--no-assume-unchanged', $path), $temporary->path);
                }
                if ($kind === 'untracked') {
                    unlink($temporary->child($path));
                } else {
                    // Only this disposable fixture is restored, never the source worktree.
                    $runner->mustRun(array('git', 'restore', '--source=HEAD', '--staged', '--worktree', $path), $temporary->path);
                }
            }
        } finally {
            $temporary->cleanup();
        }
    }

    public function testPublisherRejectsCommittedInventoryManifestLicenseAndDependencyFailures(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $mapping = $this->readJsonFile('tools/release-package-destinations.json');
        $cases = array(array('tools/release-package-destinations.json', '{'));
        foreach (array('missing', 'duplicate', 'reordered', 'destination', 'verified') as $kind) {
            $map = $mapping;
            if ($kind === 'missing') { array_pop($map['packages']); }
            if ($kind === 'duplicate') { $map['packages'][1] = $map['packages'][0]; }
            if ($kind === 'reordered') { $map['packages'] = array_reverse($map['packages']); }
            if ($kind === 'destination') { $map['packages'][0]['repository'] = 'attacker/contracts'; }
            if ($kind === 'verified') { $map['status'] = 'verified'; }
            $cases[] = array('tools/release-package-destinations.json', json_encode($map));
        }
        foreach (array('name' => 'evolvephp/wrong', 'license' => 'MIT', 'require' => array('php' => '^8.3'), 'version' => '2.0.0-beta.1') as $field => $value) {
            $manifest = $this->readJsonFile('packages/contracts/composer.json');
            $manifest[$field] = $value;
            $cases[] = array('packages/contracts/composer.json', json_encode($manifest));
        }
        $manifest = $this->readJsonFile('packages/contracts/composer.json');
        $manifest['require']['evolvephp/core'] = '^2.0';
        $cases[] = array('packages/contracts/composer.json', json_encode($manifest));
        $cases[] = array('packages/contracts/LICENSE.md', 'wrong license');
        $cases[] = array('packages/contracts/unsafe name', 'unsafe Git path');
        $canonical = $this->readJsonFile('release-packages.json');
        $canonical['packages'] = array_reverse($canonical['packages']);
        $cases[] = array('release-packages.json', json_encode($canonical));
        foreach ($cases as [$path, $contents]) {
            $temporary = $this->createPlannerFixture($path, $contents);
            try {
                $result = $this->runPublisher($temporary->path);
                $this->assertSame(1, $result->exitCode, $path . $result->output());
                $this->assertSame('', $result->stdout);
            } finally {
                $temporary->cleanup();
            }
        }
    }

    public function testPublisherObservationsCannotAuthorizeAndConflictsOrPartialStateFailClosed(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $temporary = $this->createPlannerFixture();
        $evidence = createTemporaryDirectory('evolvephp-publisher-observations-');
        try {
            $result = $this->runPublisher($temporary->path);
            $this->assertSame(0, $result->exitCode, $result->output());
            $plan = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
            $observations = array('version' => 1, 'source' => $plan['source'], 'packages' => array());
            foreach ($plan['packages'] as $package) {
                $observations['packages'][] = array(
                    'name' => $package['name'], 'destination' => $package['destination'],
                    'split_commit' => null, 'split_tree' => null, 'remote_commit' => null,
                    'tag_ref' => 'refs/tags/2.0.0-beta.1', 'tag_object' => null, 'tag_commit' => null,
                );
            }
            $path = $evidence->child('observations.json');
            file_put_contents($path, json_encode($observations));
            $empty = $this->runPublisher($temporary->path, array('--observations=' . $path));
            $this->assertSame(0, $empty->exitCode, $empty->output());
            $this->assertSame('reported-absent-unverified', json_decode($empty->stdout, true)['remote_observation']);
            $complete = $observations;
            foreach ($complete['packages'] as $i => &$entry) {
                $entry['split_tree'] = $plan['packages'][$i]['package_tree'];
                $entry['split_commit'] = $entry['remote_commit'] = $entry['tag_commit'] = str_repeat('a', 40);
                $entry['tag_object'] = str_repeat('b', 40);
            }
            unset($entry);
            file_put_contents($path, json_encode($complete));
            $consistent = $this->runPublisher($temporary->path, array('--observations=' . $path));
            $this->assertSame(0, $consistent->exitCode, $consistent->output());
            $this->assertSame('reported-complete-unverified', json_decode($consistent->stdout, true)['remote_observation']);
            $this->assertFalse(json_decode($consistent->stdout, true)['publication_enabled']);
            $this->assertNull(json_decode($consistent->stdout, true)['packages'][0]['split_commit']);
            $cases = array();
            $incomplete = $complete; $incomplete['packages'][0]['tag_object'] = null; $cases[] = $incomplete;
            $partial = $observations; $partial['packages'][0] = $complete['packages'][0]; $cases[] = $partial;
            foreach (array('remote_commit', 'tag_commit', 'split_tree', 'tag_ref', 'destination', 'name') as $field) {
                $bad = $complete; $bad['packages'][0][$field] = str_repeat('c', 40); $cases[] = $bad;
            }
            $bad = $complete; $bad['packages'][1] = $bad['packages'][0]; $cases[] = $bad;
            $bad = $complete; $bad['source']['tree'] = str_repeat('0', 40); $cases[] = $bad;
            foreach ($cases as $case) {
                file_put_contents($path, json_encode($case));
                $failed = $this->runPublisher($temporary->path, array('--observations=' . $path));
                $this->assertSame(1, $failed->exitCode, $failed->output());
                $this->assertSame('', $failed->stdout);
                $this->assertSame($failed->stderr, $this->runPublisher($temporary->path, array('--observations=' . $path))->stderr);
            }
            file_put_contents($path, json_encode($observations));
            $this->assertSame($empty->stdout, $this->runPublisher($temporary->path, array('--observations=' . $path))->stdout);
        } finally {
            $temporary->cleanup();
            $evidence->cleanup();
        }
    }

    public function testPublisherRejectsSymlinkGitlinkAndWorktreeConfig(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $runner = new ReleaseValidationProcessRunner();
        foreach (array('120000', '160000', 'worktree-config') as $kind) {
            $temporary = $this->createPlannerFixture();
            try {
                if ($kind === 'worktree-config') {
                    $runner->mustRun(array('git', 'config', 'extensions.worktreeConfig', 'true'), $temporary->path);
                } else {
                    $runner->mustRun(array('git', 'config', 'core.symlinks', 'false'), $temporary->path);
                    $path = 'packages/contracts/unsupported';
                    if ($kind === '120000') {
                        file_put_contents($temporary->child($path), 'README.md');
                        $object = trim($runner->mustRun(array('git', 'hash-object', '-w', $path), $temporary->path)->stdout);
                    } else {
                        mkdir($temporary->child($path));
                        $object = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $temporary->path)->stdout);
                    }
                    $runner->mustRun(array('git', 'update-index', '--add', '--cacheinfo', $kind, $object, $path), $temporary->path);
                    $runner->mustRun(array('git', 'commit', '-m', 'Unsafe object fixture'), $temporary->path);
                }
                $before = $this->plannerFixtureState($runner, $temporary->path);
                $result = $this->runPublisher($temporary->path);
                $this->assertSame(1, $result->exitCode, $kind . ': ' . $result->output());
                $this->assertSame('', $result->stdout);
                $this->assertSame($before, $this->plannerFixtureState($runner, $temporary->path));
            } finally {
                $temporary->cleanup();
            }
        }
    }

    private function runPublisher(string $root, array $options = array(), array $environment = array()): ReleaseValidationProcessResult
    {
        $runner = new ReleaseValidationProcessRunner();
        $pins = array();
        foreach (array('commit' => 'HEAD', 'tree' => 'HEAD^{tree}', 'branch' => '--abbrev-ref') as $key => $ref) {
            $command = $key === 'branch' ? array('git', 'rev-parse', '--abbrev-ref', 'HEAD') : array('git', 'rev-parse', $ref);
            if (!array_filter($options, static fn ($option) => str_starts_with($option, '--source-' . $key . '='))) {
                $pins[] = '--source-' . $key . '=' . trim($runner->mustRun($command, $root)->stdout);
            }
        }
        return $runner->run(array_merge(array(PHP_BINARY, $this->path('tools/release-package-publisher.php'), '--root=' . $root), $pins, $options), null, $environment);
    }

    public function testReleasePlanIsDeterministicBoundToGitAndHasNoSideEffects(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $temporary = $this->createPlannerFixture();
        $runner = new ReleaseValidationProcessRunner();
        try {
            $root = $temporary->path;
            $before = $this->plannerFixtureState($runner, $root);
            $commit = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $root)->stdout);
            $tree = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD^{tree}'), $root)->stdout);
            $options = array('--source-commit=' . $commit, '--source-tree=' . $tree, '--source-branch=feature/release-plan-test');
            $first = $this->runPlanner($root, $options);
            $second = $this->runPlanner($root, $options);
            $this->assertSame(0, $first->exitCode, $first->output());
            $this->assertSame('', $first->stderr);
            $this->assertSame(0, $second->exitCode, $second->output());
            $this->assertSame($first->stdout, $second->stdout);
            $plan = json_decode($first->stdout, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('dry-run', $plan['mode']);
            $this->assertFalse($plan['publication_enabled']);
            $this->assertSame('2.0.0-beta.1', $plan['proposed_version']);
            $this->assertSame(array('branch' => 'feature/release-plan-test', 'commit' => $commit, 'tree' => $tree), $plan['source']);
            $this->assertCount(34, $plan['packages']);
            $this->assertSame(array_column($this->releasePackages(), 'name'), array_column($plan['packages'], 'name'));
            foreach ($plan['packages'] as $package) {
                $this->assertSame('josiahking/evolvephp-' . packageSlug($package['name']), $package['destination']);
                $this->assertSame('proposed-unverified', $package['destination_status']);
                $expectedTree = trim($runner->mustRun(array('git', 'rev-parse', $commit . ':' . $package['directory']), $root)->stdout);
                $this->assertSame($expectedTree, $package['package_tree']);
                $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $package['composer_blob']);
            }
            $this->assertSame($before, $this->plannerFixtureState($runner, $root));
            $runner->mustRun(array('git', 'checkout', '--detach'), $root);
            $detached = $this->runPlanner($root, array('--source-branch=HEAD'));
            $this->assertSame(0, $detached->exitCode, $detached->output());
            $this->assertSame('HEAD', json_decode($detached->stdout, true)['source']['branch']);
        } finally {
            $temporary->cleanup();
        }
    }

    public function testReleasePlanRejectsWrongIdentityAndUnsupportedArgumentsWithoutMutation(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $temporary = $this->createPlannerFixture();
        $runner = new ReleaseValidationProcessRunner();
        try {
            $before = $this->plannerFixtureState($runner, $temporary->path);
            foreach (array(
                array('--source-commit=' . str_repeat('0', 40)),
                array('--source-tree=' . str_repeat('0', 40)),
                array('--source-branch=2.x'),
                array('--source-commit=HEAD'),
                array('--publish'),
                array('--push'),
                array('--version=2.0.0'),
                array('--composer=composer'),
                array('--root=' . $temporary->path, '--root=' . $temporary->path),
            ) as $options) {
                $result = $this->runPlanner($temporary->path, $options);
                $this->assertSame(1, $result->exitCode, implode(' ', $options) . $result->output());
                $this->assertSame('', $result->stdout);
                $this->assertStringContainsString('release plan failed:', $result->stderr);
                $this->assertSame($before, $this->plannerFixtureState($runner, $temporary->path));
            }
            $runner->mustRun(array('git', 'checkout', '-b', 'master'), $temporary->path);
            $result = $this->runPlanner($temporary->path);
            $this->assertSame(1, $result->exitCode);
            $this->assertStringContainsString('branch', $result->stderr);
        } finally {
            $temporary->cleanup();
        }
    }

    public function testReleasePlanRejectsDirtyWorktreeIndexAndUntrackedFiles(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        foreach (array('worktree', 'index', 'untracked', 'hidden') as $kind) {
            $temporary = $this->createPlannerFixture();
            $runner = new ReleaseValidationProcessRunner();
            try {
                $path = $kind === 'untracked' ? 'untracked.txt' : 'packages/contracts/composer.json';
                file_put_contents($temporary->child($path), "\n", FILE_APPEND);
                if ($kind === 'index') {
                    $runner->mustRun(array('git', 'add', $path), $temporary->path);
                }
                if ($kind === 'hidden') {
                    $runner->mustRun(array('git', 'update-index', '--assume-unchanged', $path), $temporary->path);
                }
                $before = $this->plannerFixtureState($runner, $temporary->path);
                $result = $this->runPlanner($temporary->path);
                $this->assertSame(1, $result->exitCode, $kind . $result->output());
                $this->assertSame('', $result->stdout);
                $this->assertMatchesRegularExpression('/dirty|hidden index/i', $result->stderr);
                $this->assertSame($before, $this->plannerFixtureState($runner, $temporary->path));
            } finally {
                $temporary->cleanup();
            }
        }
    }

    public function testReleasePlanRejectsInvalidCommittedMappingsAndMetadata(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $mapping = $this->readJsonFile('tools/release-package-destinations.json');
        $cases = array();
        $missing = $mapping;
        array_pop($missing['packages']);
        $cases['incomplete'] = array('tools/release-package-destinations.json', json_encode($missing), 'destinations');
        $duplicate = $mapping;
        $duplicate['packages'][1] = $duplicate['packages'][0];
        $cases['duplicate'] = array('tools/release-package-destinations.json', json_encode($duplicate), 'destination');
        foreach (array('attacker/evolvephp-contracts', 'josiahking/../contracts', 'https://github.com/josiahking/evolvephp-contracts', 'josiahking/evolvephp-core') as $destination) {
            $invalid = $mapping;
            $invalid['packages'][0]['repository'] = $destination;
            $cases[$destination] = array('tools/release-package-destinations.json', json_encode($invalid), 'destination');
        }
        $reordered = $mapping;
        $reordered['packages'] = array_reverse($reordered['packages']);
        $cases['reordered'] = array('tools/release-package-destinations.json', json_encode($reordered), 'destination');
        $cases['malformed'] = array('tools/release-package-destinations.json', '{', 'JSON');
        $cases['missing-file'] = array('tools/release-package-destinations.json', null, 'destination');
        foreach (array('name' => 'evolvephp/wrong', 'license' => 'MIT', 'version' => '2.0.0-beta.1', 'minimum-stability' => 'beta', 'prefer-stable' => true, 'require' => array('php' => '^8.3')) as $field => $value) {
            $manifest = $this->readJsonFile('packages/contracts/composer.json');
            $manifest[$field] = $value;
            $cases['manifest-' . $field] = array('packages/contracts/composer.json', json_encode($manifest), 'manifest');
        }
        $manifest = $this->readJsonFile('packages/core/composer.json');
        $manifest['require']['evolvephp/contracts'] = '^2.0@beta';
        $cases['constraint'] = array('packages/core/composer.json', json_encode($manifest), 'constraint');
        foreach ($cases as $name => [$path, $contents, $message]) {
            $temporary = $this->createPlannerFixture($path, $contents);
            try {
                $result = $this->runPlanner($temporary->path);
                $this->assertSame(1, $result->exitCode, $name . ': ' . $result->output());
                $this->assertSame('', $result->stdout);
                $this->assertStringContainsString($message, $result->stderr, $name);
            } finally {
                $temporary->cleanup();
            }
        }
    }

    public function testBetaConsumerMatrixUsesRealOfflineComposerAndPreservesAlphaStable(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $this->loadPrereleaseConsumerValidatorClass();
        $validator = new PrereleaseConsumerValidator();
        $this->assertTrue(method_exists($validator, 'validateBetaConsumers'), 'The existing validator must own the Beta matrix.');
        $temporary = createTemporaryDirectory('evolvephp-beta-test-');
        $runner = new ReleaseValidationProcessRunner();
        try {
            $splitRoots = array();
            foreach ($this->releasePackages() as $package) {
                $directory = $temporary->child('packages/' . packageSlug($package['name']));
                mkdir($directory, 0777, true);
                copy($this->path($package['directory'] . '/composer.json'), $directory . '/composer.json');
                $this->initializeGitFixture($runner, $directory);
                $runner->mustRun(array('git', 'add', 'composer.json'), $directory);
                $runner->mustRun(array('git', 'commit', '-m', 'Synthetic package metadata'), $directory);
                $splitRoots[$package['name']] = $directory;
            }
            $repositories = (new ReflectionMethod($validator, 'createTaggedRepositories'))->invoke($validator, $temporary->child('beta-repositories'), $splitRoots, '2.0.0-beta.1');
            $results = (new ReflectionMethod($validator, 'validateBetaConsumers'))->invoke(
                $validator, $temporary, null, $repositories, loadLockedRuntimePackageRepositoryPackages($this->root), $this->releasePackages()
            );
            $this->assertSame(array('Beta case I', 'Beta case J', 'Beta case K', 'Beta case L', 'Beta case M', 'Beta full-graph case N', 'Beta full-graph case O'), array_column($results, 'name'));
            $this->assertSame(array('expected failure', 'expected failure', 'expected failure', 'success', 'success', 'success', 'success'), array_column($results, 'outcome'));
            foreach (array_slice($results, 3) as $result) {
                $this->assertStringContainsString('evolvephp/contracts 2.0.0-beta.1', $result['detail']);
                $this->assertStringNotContainsString('alpha', $result['detail']);
            }
            foreach (array_slice($results, 5) as $result) {
                $this->assertSame(34, substr_count($result['detail'], '2.0.0-beta.1'));
            }
            $consumer = readJsonFile($temporary->child('consumers/beta-case-l/composer.json'), 'Beta consumer');
            $this->assertSame('beta', $consumer['minimum-stability']);
            $this->assertTrue($consumer['prefer-stable']);
            $this->assertContains(array('packagist.org' => false), $consumer['repositories']);
            foreach ($splitRoots as $name => $directory) {
                $this->assertSame('', trim($runner->mustRun(array('git', 'status', '--porcelain'), $directory)->stdout), $name);
            }
        } finally {
            $temporary->cleanup();
        }
    }

    private function createPlannerFixture(?string $changedPath = null, ?string $contents = null): ReleaseValidationTemporaryDirectory
    {
        $this->assertFileExists($this->path('tools/publish-package-splits.php'));
        $temporary = createTemporaryDirectory('evolvephp-plan-test-');
        $runner = new ReleaseValidationProcessRunner();
        $this->initializeGitFixture($runner, $temporary->path);
        $runner->mustRun(array('git', 'checkout', '-b', 'feature/release-plan-test'), $temporary->path);
        foreach (array('LICENSE.md', 'release-packages.json', 'tools/release-package-destinations.json') as $path) {
            $this->writeFixtureFile($temporary->path, $path, $this->readProjectFile($path));
        }
        foreach ($this->releasePackages() as $package) {
            foreach (array('composer.json', 'LICENSE.md', 'README.md') as $file) {
                $path = $package['directory'] . '/' . $file;
                $this->writeFixtureFile($temporary->path, $path, $this->readProjectFile($path));
            }
        }
        if ($changedPath !== null) {
            if ($contents === null) {
                unlink($temporary->child($changedPath));
            } else {
                $this->writeFixtureFile($temporary->path, $changedPath, $contents);
            }
        }
        $runner->mustRun(array('git', 'add', '.'), $temporary->path);
        $runner->mustRun(array('git', 'commit', '-m', 'Synthetic release plan fixture'), $temporary->path);
        return $temporary;
    }

    private function runPlanner(string $root, array $options = array()): ReleaseValidationProcessResult
    {
        return (new ReleaseValidationProcessRunner())->run(
            array_merge(array(PHP_BINARY, $this->path('tools/publish-package-splits.php'), '--root=' . $root), $options),
            null,
            array('COMPOSER_DISABLE_NETWORK' => '1', 'GIT_TERMINAL_PROMPT' => '0', 'GIT_ALLOW_PROTOCOL' => 'file')
        );
    }

    private function plannerFixtureState(ReleaseValidationProcessRunner $runner, string $root): array
    {
        return array(
            'refs' => $runner->mustRun(array('git', 'for-each-ref'), $root)->stdout,
            'head' => $runner->mustRun(array('git', 'rev-parse', 'HEAD'), $root)->stdout,
            'index' => hash_file('sha256', $root . '/.git/index'),
            'diff' => $runner->mustRun(array('git', '--no-optional-locks', 'diff', '--no-ext-diff'), $root)->stdout,
            'files' => $runner->mustRun(array('git', '--no-optional-locks', 'status', '--porcelain=v1', '--untracked-files=all'), $root)->stdout,
            'config' => hash_file('sha256', $root . '/.git/config'),
        );
    }


    public function test_mcp_independent_consumer_fixture_is_invoked_after_disposable_install(): void
    {
        $root = dirname(__DIR__, 2);
        $validator = (string) file_get_contents($root . '/tools/validate-prerelease-consumers.php');
        $fixture = $root . '/tools/fixtures/mcp-independent-consumer.php';
        self::assertFileExists($fixture);
        self::assertStringContainsString('runMcpIndependentConsumer', $validator);
        self::assertStringContainsString('mcp-independent-consumer.php', $validator);
        self::assertStringContainsString('vendor/autoload.php', (string) file_get_contents($fixture));
    }

    public function testMcpIndependentConsumerUsesLocalLockedPackagesInsteadOfRemoteDistributions(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $this->loadPrereleaseConsumerValidatorClass();

        $packages = loadLockedRuntimePackageRepositoryPackages($this->root);
        $packagesByName = array_column($packages, null, 'name');
        $psr7 = $packagesByName['guzzlehttp/psr7'];
        $this->assertStringStartsWith('https://', $psr7['dist']['url']);

        $temporary = createTemporaryDirectory('evolvephp-mcp-offline-test-');

        try {
            $validator = new PrereleaseConsumerValidator();
            $offline = (new ReflectionMethod($validator, 'prepareMcpOfflineRepository'))
                ->invoke($validator, $this->root, $temporary, $packages);
            $consumer = (new ReflectionMethod($validator, 'createConsumer'))->invoke(
                $validator,
                $temporary,
                'MCP independent case',
                array('evolvephp/mcp' => $temporary->child('first-party')),
                $packages,
                array('evolvephp/mcp' => '^2.0@alpha', 'guzzlehttp/psr7' => '^3.1'),
                array('minimum-stability' => 'alpha', 'prefer-stable' => true),
                $offline,
            );
            $manifest = readJsonFile($consumer . '/composer.json', 'MCP test consumer');
            $path = $manifest['repositories'][0];
            $this->assertSame('path', $path['type']);
            $this->assertFalse($path['options']['symlink']);
            $this->assertSame('3.1.0', $path['options']['versions']['guzzlehttp/psr7']);
            $this->assertSame(array_keys($offline['versions']), $path['only']);
            $this->assertTrue($path['canonical']);
            foreach ($offline['versions'] as $name => $version) {
                $this->assertSame($packagesByName[$name]['version'], $version);
                $copy = $temporary->child('offline-mcp-vendor/' . $name);
                $this->assertDirectoryExists($copy);
                $this->assertFalse(is_link($copy));
                $this->assertSame($name, readJsonFile($copy . '/composer.json', 'copied package')['name']);
            }
            $this->assertSame(array('packagist.org' => false), $manifest['repositories'][1]);
            $this->assertSame(array(), array_intersect($path['only'], array_column($manifest['repositories'][2]['package'], 'name')));
            $this->assertSame('vcs', $manifest['repositories'][3]['type']);
        } finally {
            $temporary->cleanup();
        }
    }

    public function testMcpOfflineCopyRejectsMissingPackageDirectory(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $this->loadPrereleaseConsumerValidatorClass();
        $temporary = createTemporaryDirectory('evolvephp-mcp-missing-test-');

        try {
            $validator = new PrereleaseConsumerValidator();
            $this->expectException(ReleaseValidationFailure::class);
            $this->expectExceptionMessage('Locked MCP package directory is missing or a symlink');
            (new ReflectionMethod($validator, 'copyMcpPackageDirectory'))->invoke(
                $validator,
                $temporary->child('missing'),
                $temporary->child('copy'),
            );
        } finally {
            $temporary->cleanup();
        }
    }

    public function testMcpOfflineCopyRejectsNestedSymlink(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $this->loadPrereleaseConsumerValidatorClass();
        $temporary = createTemporaryDirectory('evolvephp-mcp-symlink-test-');

        try {
            $source = $temporary->child('source');
            mkdir($source);
            file_put_contents($temporary->child('outside.txt'), 'outside');
            if (!@symlink($temporary->child('outside.txt'), $source . '/linked.txt')) {
                $this->markTestSkipped('This host cannot create a symbolic link.');
            }

            $validator = new PrereleaseConsumerValidator();
            $this->expectException(ReleaseValidationFailure::class);
            $this->expectExceptionMessage('Refusing to copy symlink into MCP offline repository');
            (new ReflectionMethod($validator, 'copyMcpPackageDirectory'))->invoke(
                $validator,
                $source,
                $temporary->child('copy'),
            );
        } finally {
            $temporary->cleanup();
        }
    }

    public function testMcpFirstPartyOfflineRepositoryUsesVerifiedAlphaSnapshots(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $this->loadPrereleaseConsumerValidatorClass();
        $temporary = createTemporaryDirectory('evolvephp-mcp-first-party-test-');

        try {
            $repositories = $this->createTaggedMcpRepositories($temporary);
            $validator = new PrereleaseConsumerValidator();
            $offline = (new ReflectionMethod($validator, 'prepareMcpFirstPartyOfflineRepository'))
                ->invoke($validator, $temporary, $repositories);
            $expected = array(
                'evolvephp/contracts' => '2.0.0-alpha.1',
                'evolvephp/core' => '2.0.0-alpha.1',
                'evolvephp/mcp' => '2.0.0-alpha.1',
                'evolvephp/secret-contracts' => '2.0.0-alpha.1',
            );
            $this->assertSame($expected, $offline['versions']);

            foreach ($expected as $name => $version) {
                $copy = $temporary->child('offline-mcp-first-party/' . str_replace('/', '-', $name));
                $this->assertDirectoryExists($copy);
                $this->assertFalse(is_link($copy));
                $this->assertFileDoesNotExist($copy . '/.git');
                $this->assertDirectoryDoesNotExist($copy . '/.git');
                $this->assertFileExists($copy . '/src/Marker.php');
                $this->assertSame($name, readJsonFile($copy . '/composer.json', 'copied first-party package')['name']);
            }

            $external = array('root' => $temporary->child('external'), 'versions' => array('guzzlehttp/psr7' => '3.1.0'));
            $consumer = (new ReflectionMethod($validator, 'createConsumer'))->invoke(
                $validator,
                $temporary,
                'MCP independent case',
                $repositories,
                array(array('name' => 'guzzlehttp/psr7', 'version' => '3.1.0')),
                array('evolvephp/mcp' => '^2.0@alpha', 'guzzlehttp/psr7' => '^3.1'),
                array('minimum-stability' => 'alpha', 'prefer-stable' => true),
                $external,
                $offline,
            );
            $manifest = readJsonFile($consumer . '/composer.json', 'MCP first-party consumer');
            $this->assertSame('path', $manifest['repositories'][0]['type']);
            $this->assertSame(array('guzzlehttp/psr7'), $manifest['repositories'][0]['only']);
            $this->assertSame(array('guzzlehttp/psr7' => '3.1.0'), $manifest['repositories'][0]['options']['versions']);
            $this->assertSame('path', $manifest['repositories'][1]['type']);
            $this->assertSame(array_keys($expected), $manifest['repositories'][1]['only']);
            $this->assertSame($expected, $manifest['repositories'][1]['options']['versions']);
            $this->assertFalse($manifest['repositories'][1]['options']['symlink']);
            $this->assertTrue($manifest['repositories'][1]['canonical']);
            $this->assertSame(array('packagist.org' => false), $manifest['repositories'][2]);
            $this->assertCount(8, $manifest['repositories']);
            $this->assertSame(array('vcs', 'vcs', 'vcs', 'vcs'), array_column(array_slice($manifest['repositories'], 4), 'type'));
        } finally {
            $temporary->cleanup();
        }
    }

    public function testMcpFirstPartyOfflineRepositoryRejectsMissingPackage(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $this->loadPrereleaseConsumerValidatorClass();
        $temporary = createTemporaryDirectory('evolvephp-mcp-first-party-missing-test-');

        try {
            $repositories = $this->createTaggedMcpRepositories($temporary);
            unset($repositories['evolvephp/core']);
            $this->expectException(ReleaseValidationFailure::class);
            $this->expectExceptionMessage('Missing MCP first-party alpha repository: evolvephp/core');
            (new ReflectionMethod(new PrereleaseConsumerValidator(), 'prepareMcpFirstPartyOfflineRepository'))
                ->invoke(new PrereleaseConsumerValidator(), $temporary, $repositories);
        } finally {
            $temporary->cleanup();
        }
    }

    public function testMcpFirstPartyOfflineRepositoryRejectsWrongManifestName(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $this->loadPrereleaseConsumerValidatorClass();
        $temporary = createTemporaryDirectory('evolvephp-mcp-first-party-name-test-');

        try {
            $repositories = $this->createTaggedMcpRepositories($temporary, 'evolvephp/wrong');
            $this->expectException(ReleaseValidationFailure::class);
            $this->expectExceptionMessage('MCP first-party package name mismatch: evolvephp/mcp');
            (new ReflectionMethod(new PrereleaseConsumerValidator(), 'prepareMcpFirstPartyOfflineRepository'))
                ->invoke(new PrereleaseConsumerValidator(), $temporary, $repositories);
        } finally {
            $temporary->cleanup();
        }
    }

    public function testMcpFirstPartyOfflineRepositoryRejectsTagBehindHead(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $this->loadPrereleaseConsumerValidatorClass();
        $temporary = createTemporaryDirectory('evolvephp-mcp-first-party-tag-test-');

        try {
            $repositories = $this->createTaggedMcpRepositories($temporary);
            $mcp = $repositories['evolvephp/mcp'];
            file_put_contents($mcp . '/src/New.php', '<?php');
            $runner = new ReleaseValidationProcessRunner();
            $runner->mustRun(array('git', '-C', $mcp, 'add', 'src/New.php'));
            $runner->mustRun(array('git', '-C', $mcp, 'commit', '-m', 'Advance HEAD'));
            $this->expectException(ReleaseValidationFailure::class);
            $this->expectExceptionMessage('MCP first-party alpha tag does not match HEAD: evolvephp/mcp');
            (new ReflectionMethod(new PrereleaseConsumerValidator(), 'prepareMcpFirstPartyOfflineRepository'))
                ->invoke(new PrereleaseConsumerValidator(), $temporary, $repositories);
        } finally {
            $temporary->cleanup();
        }
    }


    public function testMcpFirstPartySnapshotRejectsTrackedSymlinkMode(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $this->loadPrereleaseConsumerValidatorClass();
        $temporary = createTemporaryDirectory('evolvephp-mcp-first-party-link-test-');

        try {
            $repositories = $this->createTaggedMcpRepositories($temporary);
            $mcp = $repositories['evolvephp/mcp'];
            file_put_contents($mcp . '/src/linked.txt', 'Marker.php');
            $runner = new ReleaseValidationProcessRunner();
            $hash = trim($runner->mustRun(array('git', '-C', $mcp, 'hash-object', '-w', 'src/linked.txt'))->stdout);
            $runner->mustRun(array('git', '-C', $mcp, 'update-index', '--add', '--cacheinfo', '120000', $hash, 'src/linked.txt'));
            $validator = new PrereleaseConsumerValidator();
            $this->expectException(ReleaseValidationFailure::class);
            $this->expectExceptionMessage('MCP tagged package contains a symlink or unsupported Git entry');
            (new ReflectionMethod($validator, 'copyTaggedMcpPackageSnapshot'))
                ->invoke($validator, $mcp, $temporary->child('copy'));
        } finally {
            $temporary->cleanup();
        }
    }

    public function testMcpFirstPartySnapshotRejectsMissingTrackedFile(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $this->loadPrereleaseConsumerValidatorClass();
        $temporary = createTemporaryDirectory('evolvephp-mcp-first-party-file-test-');

        try {
            $repositories = $this->createTaggedMcpRepositories($temporary);
            $mcp = $repositories['evolvephp/mcp'];
            unlink($mcp . '/src/Marker.php');
            $validator = new PrereleaseConsumerValidator();
            $this->expectException(ReleaseValidationFailure::class);
            $this->expectExceptionMessage('MCP tagged package source file is missing or unsafe');
            (new ReflectionMethod($validator, 'copyTaggedMcpPackageSnapshot'))
                ->invoke($validator, $mcp, $temporary->child('copy'));
        } finally {
            $temporary->cleanup();
        }
    }

    public function testConsumerWithoutMcpOfflineRepositoriesRetainsExistingRepositoryOrder(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $this->loadPrereleaseConsumerValidatorClass();
        $temporary = createTemporaryDirectory('evolvephp-consumer-order-test-');

        try {
            $validator = new PrereleaseConsumerValidator();
            $consumer = (new ReflectionMethod($validator, 'createConsumer'))->invoke(
                $validator,
                $temporary,
                'Alpha case C',
                array('evolvephp/core' => $temporary->child('tagged-core')),
                array(array('name' => 'psr/container', 'version' => '2.0.2')),
                array('evolvephp/core' => '^2.0'),
                array('minimum-stability' => 'alpha', 'prefer-stable' => true),
            );
            $manifest = readJsonFile($consumer . '/composer.json', 'unchanged consumer');
            $this->assertSame(array('packagist.org' => false), $manifest['repositories'][0]);
            $this->assertSame('package', $manifest['repositories'][1]['type']);
            $this->assertSame('vcs', $manifest['repositories'][2]['type']);
            $this->assertCount(3, $manifest['repositories']);
        } finally {
            $temporary->cleanup();
        }
    }
    /**
     * @return array<string, string>
     */
    private function createTaggedMcpRepositories(ReleaseValidationTemporaryDirectory $temporary, ?string $mcpManifestName = null): array
    {
        $runner = new ReleaseValidationProcessRunner();
        $requires = array(
            'evolvephp/contracts' => array('psr/container' => '^2.0'),
            'evolvephp/core' => array('evolvephp/contracts' => '^2.0'),
            'evolvephp/mcp' => array(
                'evolvephp/contracts' => '^2.0',
                'evolvephp/core' => '^2.0',
                'evolvephp/secret-contracts' => '^2.0',
                'mcp/sdk' => '^0.8',
            ),
            'evolvephp/secret-contracts' => array('evolvephp/contracts' => '^2.0'),
        );
        $repositories = array();

        foreach ($requires as $name => $dependencies) {
            $repository = $temporary->child('alpha-repositories/' . str_replace('/', '-', $name));
            mkdir($repository, 0777, true);
            $runner->mustRun(array('git', 'init', $repository));
            $runner->mustRun(array('git', '-C', $repository, 'config', 'user.name', 'EvolvePHP Test'));
            $runner->mustRun(array('git', '-C', $repository, 'config', 'user.email', 'evolvephp-test@example.com'));
            $manifest = array(
                'name' => $name === 'evolvephp/mcp' && $mcpManifestName !== null ? $mcpManifestName : $name,
                'type' => 'library',
                'require' => $dependencies,
            );
            file_put_contents($repository . '/composer.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            mkdir($repository . '/src');
            file_put_contents($repository . '/src/Marker.php', '<?php');
            $runner->mustRun(array('git', '-C', $repository, 'add', '.'));
            $runner->mustRun(array('git', '-C', $repository, 'commit', '-m', 'Tagged package fixture'));
            $runner->mustRun(array('git', '-C', $repository, 'tag', '2.0.0-alpha.1'));
            $repositories[$name] = $repository;
        }

        return $repositories;
    }

    private function loadPrereleaseConsumerValidatorClass(): void
    {
        if (class_exists(PrereleaseConsumerValidator::class, false)) {
            return;
        }

        $source = (string) file_get_contents($this->root . '/tools/validate-prerelease-consumers.php');
        $start = strpos($source, 'final class PrereleaseConsumerValidator');
        $end = strpos($source, "\ntry {\n    \$options = parseReleaseValidationArguments", $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        eval(substr($source, $start, $end - $start));
    }

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testReleaseSplitConsumerAndSkeletonValidationToolsAreRepositoryOwnedPhpEntrypoints(): void
    {
        $this->assertFileExists($this->path('tools/release-validation-common.php'));

        foreach (array(
            'tools/validate-package-splits.php',
            'tools/validate-prerelease-consumers.php',
            'tools/validate-skeleton-project.php',
        ) as $path) {
            $content = $this->readProjectFile($path);

            $this->assertStringStartsWith('<?php declare(strict_types=1);', $content);
            $this->assertStringContainsString("require_once __DIR__ . '/release-validation-common.php';", $content);
            $this->assertStringNotContainsString('D:\\tools\\composer84\\composer.phar', $content);
            $this->assertDoesNotMatchRegularExpression('/\\b(?:curl|gh|git push|remote add)\\b/i', $content);
        }
    }

    public function testSharedHelperUsesArgumentVectorProcessesAndDoesNotRunOnInclude(): void
    {
        $content = $this->readProjectFile('tools/release-validation-common.php');

        $this->assertStringContainsString('proc_open(', $content);
        $this->assertStringContainsString('bypass_shell', $content);
        $this->assertStringContainsString('loadReleasePackages', $content);
        $this->assertStringContainsString('loadLockedPackageRepositoryPackages', $content);
        $this->assertStringContainsString('createTemporaryDirectory', $content);
        $this->assertStringContainsString('removeDirectory', $content);
        $this->assertDoesNotMatchRegularExpression('/\\b(?:shell_exec|exec|passthru|system)\\s*\\(/', $content);
        $this->assertDoesNotMatchRegularExpression('/\\b(?:git push|gh|curl|remote add|config --global)\\b/i', $content);
        $this->assertDoesNotMatchRegularExpression('/EvolvePHP .* validation passed/', $content);
    }

    public function testSharedProcessRunnerBoundsProcessesAndReportsStage(): void
    {
        require_once $this->path('tools/release-validation-common.php');

        $runner = new ReleaseValidationProcessRunner(1);
        $startedAt = microtime(true);

        try {
            $runner->run(
                array(PHP_BINARY, '-r', "fwrite(STDOUT, 'started'); sleep(3);"),
                null,
                array(),
                'slow fixture'
            );

            $this->fail('Slow fixture should time out.');
        } catch (ReleaseValidationFailure $failure) {
            $elapsed = microtime(true) - $startedAt;

            $this->assertLessThan(3.0, $elapsed, 'The timeout fixture must not wait for the full sleep duration.');
            $this->assertStringContainsString('Process timed out after 1 second during slow fixture', $failure->getMessage());
            $this->assertStringContainsString(PHP_BINARY, $failure->getMessage());
        }
    }

    public function testSharedProcessRunnerAllowsShortProcessesWithTimeout(): void
    {
        require_once $this->path('tools/release-validation-common.php');

        $runner = new ReleaseValidationProcessRunner(5);
        $result = $runner->run(array(PHP_BINARY, '-r', "fwrite(STDOUT, 'ok');"), null, array(), 'short fixture');

        $this->assertSame(0, $result->exitCode);
        $this->assertSame('ok', $result->stdout);
        $this->assertSame('', $result->stderr);
    }

    public function testSharedProcessRunnerAcceptsDisabledTimeoutForLongRunningSplitOperations(): void
    {
        require_once $this->path('tools/release-validation-common.php');

        $runner = new ReleaseValidationProcessRunner(0);
        $result = $runner->run(array(PHP_BINARY, '-r', "fwrite(STDOUT, 'ok');"), null, array(), 'disabled timeout fixture');

        $this->assertSame(0, $result->exitCode);
        $this->assertSame('ok', $result->stdout);
        $this->assertSame('', $result->stderr);
    }

    public function testSharedProcessRunnerRejectsNegativeTimeout(): void
    {
        require_once $this->path('tools/release-validation-common.php');

        $this->expectException(ReleaseValidationFailure::class);
        $this->expectExceptionMessage('Process timeout must not be negative.');

        new ReleaseValidationProcessRunner(-1);
    }

    public function testTemporaryDirectoryCleanupRemovesValidatorOwnedPath(): void
    {
        require_once $this->path('tools/release-validation-common.php');

        $temporary = createTemporaryDirectory('evolvephp-cleanup-contract-test-');
        $path = $temporary->path;

        file_put_contents($temporary->child('marker.txt'), 'marker');

        $temporary->cleanup();

        $this->assertDirectoryDoesNotExist($path);
    }

    public function testSourceStateCaptureUsesDeterministicRefEnumerationForDetachedCi(): void
    {
        $content = $this->readProjectFile('tools/release-validation-common.php');

        $this->assertStringContainsString("'for-each-ref'", $content);
        $this->assertStringContainsString("'--sort=refname'", $content);
        $this->assertStringContainsString("'--format=%(objectname) %(refname)'", $content);
        $this->assertStringContainsString("'refs/heads'", $content);
        $this->assertStringContainsString("'refs/tags'", $content);
        $this->assertStringNotContainsString("'show-ref', '--heads', '--tags'", $content);
        $this->assertStringNotContainsString('|| true', $content);
        $this->assertStringNotContainsString('2>/dev/null', $content);
    }

    public function testSourceStateCaptureSupportsDetachedRepositoriesWithoutLocalHeadsOrTags(): void
    {
        $helperPath = $this->path('tools/release-validation-common.php');

        $this->assertFileExists($helperPath, 'tools/release-validation-common.php must exist before detached source-state behaviour is tested.');

        require_once $helperPath;

        $runner = new ReleaseValidationProcessRunner();
        $temporary = createTemporaryDirectory('evolvephp-detached-source-state-test-');

        try {
            $runner->mustRun(array('git', 'init'), $temporary->path);
            $runner->mustRun(array('git', 'config', 'user.name', 'EvolvePHP Test'), $temporary->path);
            $runner->mustRun(array('git', 'config', 'user.email', 'evolvephp-test@example.com'), $temporary->path);

            file_put_contents($temporary->child('README.md'), "detached fixture\n");

            $runner->mustRun(array('git', 'add', 'README.md'), $temporary->path);
            $runner->mustRun(array('git', 'commit', '-m', 'Initial fixture'), $temporary->path);

            $branch = trim($runner->mustRun(array('git', 'symbolic-ref', '--short', 'HEAD'), $temporary->path)->stdout);
            $head = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $temporary->path)->stdout);

            $runner->mustRun(array('git', 'checkout', '--detach', $head), $temporary->path);
            $runner->mustRun(array('git', 'branch', '-D', $branch), $temporary->path);

            $state = captureSourceState($runner, $temporary->path);

            $this->assertSame($head, $state['head']);
            $this->assertSame('', $state['refs']);
            $this->assertSame('', $state['tags']);
        } finally {
            $temporary->cleanup();
        }
    }

    public function testSplitValidatorDocumentsDeterministicSplitContract(): void
    {
        $content = $this->readProjectFile('tools/validate-package-splits.php');

        foreach (array(
            '--root=',
            '--ref=',
            '--composer=',
            '--changed-from=',
            'git subtree split',
            'first split',
            'second split',
            'deterministic: yes',
            'tree equality: yes',
            'inventory equality: yes',
            'composer validate --strict: pass',
            'history commits:',
            'Source repository state preserved.',
        ) as $needle) {
            $this->assertStringContainsString($needle, $content);
        }

        $this->assertStringNotContainsString('2.0.0-alpha.1', $content);
        $this->assertStringNotContainsString('git tag', $content);
    }

    public function testSplitValidatorSelectsTargetedPackagesFromChangedPaths(): void
    {
        require_once $this->path('tools/validate-package-splits.php');

        $packages = loadReleasePackages($this->root);

        $this->assertSame(
            array('evolvephp/contracts', 'evolvephp/core', 'evolvephp/insight'),
            array_column(selectPackageSplitPackagesForChangedPaths(
                $packages,
                array(
                    'packages/insight/src/Capture/DeterministicDiagnosticSampler.php',
                    'packages/core/src/ApplicationKernel.php',
                    'packages/contracts/src/ServiceIdentifier.php',
                )
            ), 'name'),
            'Targeted selection must preserve canonical release-package order.'
        );

        $this->assertSame(
            array('evolvephp/core', 'evolvephp/insight'),
            array_column(selectPackageSplitPackagesForChangedPaths(
                $packages,
                array(
                    'packages/core/src/OldLocation.php',
                    'packages/insight/src/NewLocation.php',
                )
            ), 'name'),
            'A cross-package move must select both exposed package roots.'
        );

        $this->assertSame(
            array(),
            selectPackageSplitPackagesForChangedPaths(
                $packages,
                array(
                    'packages/core-extra/README.md',
                    'docs/local-notes.md',
                    'README.md',
                )
            ),
            'Package-directory matching must respect exact directory boundaries and allow zero selected packages.'
        );
    }

    public function testSplitValidatorDiscoversNulDelimitedChangedPathsWithSpecialCharacters(): void
    {
        require_once $this->path('tools/validate-package-splits.php');

        $runner = new ReleaseValidationProcessRunner();
        $temporary = createTemporaryDirectory('evolvephp-special-paths-');
        $fixture = $temporary->path;

        try {
            $this->initializeGitFixture($runner, $fixture);
            $this->writeFixtureFile($fixture, 'README.md', "base\n");

            $runner->mustRun(array('git', 'add', 'README.md'), $fixture);
            $runner->mustRun(array('git', 'commit', '-m', 'Base'), $fixture);
            $base = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $fixture)->stdout);

            $paths = array(
                'packages/insight/src/Capture/brackets [safe] #1.txt',
                'packages/insight/src/Capture/special name.txt',
                'packages/insight/src/Capture/unicode-cafe-' . json_decode('"\u00e9"', true) . '.txt',
            );

            foreach ($paths as $path) {
                $this->writeFixtureFile($fixture, $path, "changed\n");
            }

            $runner->mustRun(array('git', 'add', 'packages/insight/src/Capture'), $fixture);
            $runner->mustRun(array('git', 'commit', '-m', 'Special paths'), $fixture);
            $head = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $fixture)->stdout);
            $before = captureSourceState($runner, $fixture);

            $changedPaths = packageSplitChangedPaths($runner, $fixture, $base, $head);

            $this->assertSame($paths, $changedPaths);
            $this->assertSame($before, captureSourceState($runner, $fixture), 'Changed-path discovery must not mutate HEAD, refs, tags, index or worktree.');
            $this->assertSame(
                array('evolvephp/insight'),
                array_column(selectPackageSplitPackagesForChangedPaths(loadReleasePackages($this->root), $changedPaths), 'name')
            );
        } finally {
            $temporary->cleanup();
        }
    }

    public function testSplitValidatorDiscoversCrossPackageMovesWithoutRenameCollapse(): void
    {
        require_once $this->path('tools/validate-package-splits.php');

        $runner = new ReleaseValidationProcessRunner();
        $temporary = createTemporaryDirectory('evolvephp-move-paths-');
        $fixture = $temporary->path;

        try {
            $this->initializeGitFixture($runner, $fixture);
            $this->writeFixtureFile($fixture, 'packages/core/src/Moved.php', "<?php\n");

            $runner->mustRun(array('git', 'add', 'packages/core/src/Moved.php'), $fixture);
            $runner->mustRun(array('git', 'commit', '-m', 'Base'), $fixture);
            $base = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $fixture)->stdout);

            $this->writeFixtureFile($fixture, 'packages/insight/src/Moved.php', "<?php\n");
            $runner->mustRun(array('git', 'rm', 'packages/core/src/Moved.php'), $fixture);
            $runner->mustRun(array('git', 'add', 'packages/insight/src/Moved.php'), $fixture);
            $runner->mustRun(array('git', 'commit', '-m', 'Move across packages'), $fixture);
            $head = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $fixture)->stdout);

            $changedPaths = packageSplitChangedPaths($runner, $fixture, $base, $head);

            $this->assertSame(
                array('packages/core/src/Moved.php', 'packages/insight/src/Moved.php'),
                $changedPaths
            );
            $this->assertSame(
                array('evolvephp/core', 'evolvephp/insight'),
                array_column(selectPackageSplitPackagesForChangedPaths(loadReleasePackages($this->root), $changedPaths), 'name')
            );
        } finally {
            $temporary->cleanup();
        }
    }

    public function testSplitValidatorParsesNulDelimitedPathOutputWithoutTrimmingBytes(): void
    {
        require_once $this->path('tools/validate-package-splits.php');

        $paths = parsePackageSplitChangedPathOutput(" leading.txt\0trailing.txt \0tab\tpath.txt\0\0");

        $this->assertSame(array(' leading.txt', 'trailing.txt ', "tab\tpath.txt", ''), $paths);
    }

    public function testSplitValidatorFailsSafeToFullValidationForReleaseSensitivePaths(): void
    {
        require_once $this->path('tools/validate-package-splits.php');

        foreach (array(
            'release-packages.json',
            'tools/validate-package-splits.php',
            'tools/release-validation-common.php',
            'composer.json',
            '.github/workflows/quality.yml',
            'packages/core/composer.json',
            'deptrac.php',
            'phpstan.neon.dist',
            'phpunit.xml.dist',
            'tools/local-helper.php',
            'packages/core-extra/README.md',
            'config/release-helper.php',
        ) as $path) {
            $decision = decidePackageSplitValidationScope(loadReleasePackages($this->root), array($path));

            $this->assertSame('full', $decision['mode'], $path . ' must force full validation.');
            $this->assertSame(34, count($decision['packages']), $path . ' must keep the complete package map.');
        }

        $decision = decidePackageSplitValidationScope(loadReleasePackages($this->root), array('docs/release-notes.md', 'README.md'));

        $this->assertSame('targeted', $decision['mode']);
        $this->assertSame(array(), $decision['packages']);
    }

    public function testSplitValidatorClassifiesCommittedDocumentationChangesConservatively(): void
    {
        require_once $this->path('tools/validate-package-splits.php');

        $packages = loadReleasePackages($this->root);

        $documentationOnly = $this->classifyCommittedFixturePaths(array(
            'docs/release-notes.md' => "notes\n",
            'docs/guides/local-validation.md' => "guide\n",
        ));

        $this->assertSame('targeted', $documentationOnly['mode']);
        $this->assertSame(array(), $documentationOnly['packages']);

        foreach (array(
            array(
                'paths' => array('docs/release-helper.php' => "<?php\n"),
                'label' => 'docs/release-helper.php',
            ),
            array(
                'paths' => array('docs/local-validation.neon' => "parameters:\n"),
                'label' => 'docs/local-validation.neon',
            ),
            array(
                'paths' => array(
                    'docs/release-notes.md' => "notes\n",
                    'docs/release-helper.php' => "<?php\n",
                ),
                'label' => 'mixed safe documentation and helper',
            ),
        ) as $case) {
            $decision = $this->classifyCommittedFixturePaths($case['paths']);

            $this->assertSame('full', $decision['mode'], $case['label'] . ' must force full validation.');
            $this->assertSame($packages, $decision['packages'], $case['label'] . ' must preserve the complete canonical package map.');
        }
    }

    public function testSplitValidatorValidatesChangedFromRefsAndRejectsBadComparisons(): void
    {
        require_once $this->path('tools/validate-package-splits.php');

        $runner = new ReleaseValidationProcessRunner();
        $temporary = createTemporaryDirectory('evolvephp-changed-from-contract-test-');

        try {
            $runner->mustRun(array('git', 'init'), $temporary->path);
            $runner->mustRun(array('git', 'config', 'user.name', 'EvolvePHP Test'), $temporary->path);
            $runner->mustRun(array('git', 'config', 'user.email', 'evolvephp-test@example.com'), $temporary->path);

            file_put_contents($temporary->child('README.md'), "base\n");
            $runner->mustRun(array('git', 'add', 'README.md'), $temporary->path);
            $runner->mustRun(array('git', 'commit', '-m', 'Base'), $temporary->path);
            $base = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $temporary->path)->stdout);

            file_put_contents($temporary->child('README.md'), "main\n");
            $runner->mustRun(array('git', 'commit', '-am', 'Main'), $temporary->path);
            $head = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $temporary->path)->stdout);

            $runner->mustRun(array('git', 'checkout', '-b', 'side', $base), $temporary->path);
            file_put_contents($temporary->child('README.md'), "side\n");
            $runner->mustRun(array('git', 'commit', '-am', 'Side'), $temporary->path);
            $side = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $temporary->path)->stdout);
            $runner->mustRun(array('git', 'checkout', '--detach', $head), $temporary->path);

            $this->assertSame($base, resolvePackageSplitChangedFrom($runner, $temporary->path, $base, $head));

            foreach (array(
                array('', '--changed-from must not be empty.'),
                array('missing-ref', '--changed-from must resolve to a Git commit.'),
                array($side, '--changed-from must be an ancestor of --ref.'),
            ) as $case) {
                try {
                    resolvePackageSplitChangedFrom($runner, $temporary->path, $case[0], $head);
                    $this->fail($case[0] . ' should be rejected.');
                } catch (ReleaseValidationFailure $failure) {
                    $this->assertStringContainsString($case[1], $failure->getMessage());
                }
            }
        } finally {
            $temporary->cleanup();
        }
    }

    public function testChangedFromArgumentIsOnlyAcceptedBySplitValidatorContract(): void
    {
        require_once $this->path('tools/release-validation-common.php');

        $splitOptions = parseReleaseValidationArguments(
            array('validate-package-splits.php', '--root=' . $this->root, '--ref=HEAD', '--composer=composer', '--changed-from=HEAD~1'),
            true,
            array('changed-from')
        );

        $this->assertSame('HEAD~1', $splitOptions['changed-from']);

        foreach (array(
            array('validate-prerelease-consumers.php', true),
            array('validate-skeleton-project.php', false),
        ) as $case) {
            try {
                parseReleaseValidationArguments(array($case[0], '--changed-from=HEAD~1'), $case[1]);
                $this->fail($case[0] . ' should not accept --changed-from.');
            } catch (ReleaseValidationFailure $failure) {
                $this->assertStringContainsString('Unknown CLI option: --changed-from=HEAD~1', $failure->getMessage());
            }
        }
    }

    public function testTargetedSplitModeUsesCommittedPathsNoRenamesAndExistingValidationLoop(): void
    {
        $content = $this->readProjectFile('tools/validate-package-splits.php');

        $this->assertStringContainsString("'diff'", $content);
        $this->assertStringContainsString("'--name-only'", $content);
        $this->assertStringContainsString("'--no-renames'", $content);
        $this->assertStringContainsString("'-z'", $content);
        $this->assertMatchesRegularExpression('/foreach\s*\(\s*\$selectedPackages\s+as\s+\$package\s*\).*?\$this->validatePackage/s', $content);
        $this->assertStringContainsString('assertSourceStatePreserved($this->runner, $root, $sourceState);', $content);
    }

    public function testOnlySplitValidatorDisablesInternalProcessRunnerTimeout(): void
    {
        $splitContent = $this->readProjectFile('tools/validate-package-splits.php');
        $consumerContent = $this->readProjectFile('tools/validate-prerelease-consumers.php');
        $skeletonContent = $this->readProjectFile('tools/validate-skeleton-project.php');

        $this->assertStringContainsString('new ReleaseValidationProcessRunner(0)', $splitContent);
        $this->assertDoesNotMatchRegularExpression('/new\s+ReleaseValidationProcessRunner\s*\(\s*0\s*\)/', $consumerContent);
        $this->assertDoesNotMatchRegularExpression('/new\s+ReleaseValidationProcessRunner\s*\(\s*0\s*\)/', $skeletonContent);
        $this->assertStringContainsString('new ReleaseValidationProcessRunner()', $consumerContent);
        $this->assertStringContainsString('new ReleaseValidationProcessRunner()', $skeletonContent);
    }

    public function testConsumerValidatorDocumentsOfflinePrereleaseAndStableMatrix(): void
    {
        $content = $this->readProjectFile('tools/validate-prerelease-consumers.php');

        foreach (array(
            'COMPOSER_DISABLE_NETWORK',
            'packagist.org',
            '2.0.0-alpha.1',
            '2.0.0',
            'Alpha case A',
            'Alpha case B',
            'Alpha case C',
            'Alpha case D',
            'Full-graph case E',
            'Full-graph case F',
            'Full-graph case G',
            'Stable case H',
            'expected failure',
            'minimum-stability',
            'prefer-stable',
            'Source repository state preserved.',
        ) as $needle) {
            $this->assertStringContainsString($needle, $content);
        }

        $this->assertDoesNotMatchRegularExpression('/\\b(?:curl|gh|git push|remote add|config --global)\\b/i', $content);
    }

    public function testConsumerValidatorRequiresIndependentCacheAndSessionPackagesInExplicitFullGraphCases(): void
    {
        $content = $this->readProjectFile('tools/validate-prerelease-consumers.php');

        $caseEStart = strpos($content, "'Full-graph case E'");
        $caseFStart = strpos($content, "'Full-graph case F'");
        $caseGStart = strpos($content, "'Full-graph case G'");
        $caseHStart = strpos($content, "'Stable case H'");
        $postHStart = strpos($content, 'assertSourceStatePreserved', is_int($caseHStart) ? $caseHStart : 0);

        $this->assertIsInt($caseEStart);
        $this->assertIsInt($caseFStart);
        $this->assertIsInt($caseGStart);
        $this->assertIsInt($caseHStart);
        $this->assertIsInt($postHStart);

        $caseEBlock = substr($content, $caseEStart, $caseFStart - $caseEStart);
        $caseFBlock = substr($content, $caseFStart, $caseGStart - $caseFStart);
        $caseHBlock = substr($content, $caseHStart, $postHStart - $caseHStart);

        $this->assertMatchesRegularExpression(
            "/'evolvephp\\/database-contracts' => '\\^2\\.0@alpha'.*?'evolvephp\\/database-pdo' => '\\^2\\.0@alpha'.*?'evolvephp\\/cache-memory' => '\\^2\\.0@alpha'.*?'evolvephp\\/session-contracts' => '\\^2\\.0@alpha'.*?'evolvephp\\/lock-contracts' => '\\^2\\.0@alpha'.*?'evolvephp\\/queue-contracts' => '\\^2\\.0@alpha'.*?'evolvephp\\/queue-memory' => '\\^2\\.0@alpha'.*?'evolvephp\\/storage-contracts' => '\\^2\\.0@alpha'.*?'evolvephp\\/storage-local' => '\\^2\\.0@alpha'/s",
            $caseEBlock
        );
        $this->assertMatchesRegularExpression(
            "/'evolvephp\\/database-contracts' => '\\^2\\.0'.*?'evolvephp\\/database-pdo' => '\\^2\\.0'.*?'evolvephp\\/cache-memory' => '\\^2\\.0'.*?'evolvephp\\/session-contracts' => '\\^2\\.0'.*?'evolvephp\\/lock-contracts' => '\\^2\\.0'.*?'evolvephp\\/queue-contracts' => '\\^2\\.0'.*?'evolvephp\\/queue-memory' => '\\^2\\.0'.*?'evolvephp\\/storage-contracts' => '\\^2\\.0'.*?'evolvephp\\/storage-local' => '\\^2\\.0'/s",
            $caseFBlock
        );
        $this->assertMatchesRegularExpression(
            "/'evolvephp\\/database-contracts' => '\\^2\\.0'.*?'evolvephp\\/database-pdo' => '\\^2\\.0'.*?'evolvephp\\/cache-memory' => '\\^2\\.0'.*?'evolvephp\\/session-contracts' => '\\^2\\.0'.*?'evolvephp\\/lock-contracts' => '\\^2\\.0'.*?'evolvephp\\/queue-contracts' => '\\^2\\.0'.*?'evolvephp\\/queue-memory' => '\\^2\\.0'.*?'evolvephp\\/storage-contracts' => '\\^2\\.0'.*?'evolvephp\\/storage-local' => '\\^2\\.0'/s",
            $caseHBlock
        );
        $this->assertStringContainsString("'evolvephp/secret-contracts' => '^2.0@alpha'", $caseEBlock);
        $this->assertStringContainsString("'evolvephp/secret-contracts' => '^2.0'", $caseFBlock);
        $this->assertStringContainsString("'evolvephp/secret-contracts' => '^2.0'", $caseHBlock);
        $this->assertStringContainsString("'evolvephp/http-client' => '^2.0@alpha'", $caseEBlock);
        $this->assertStringContainsString("'evolvephp/job' => '^2.0@alpha'", $caseEBlock);
        $this->assertStringContainsString("'evolvephp/http-client' => '^2.0'", $caseFBlock);
        $this->assertStringContainsString("'evolvephp/job' => '^2.0'", $caseFBlock);
        $this->assertStringContainsString("'evolvephp/http-client' => '^2.0'", $caseHBlock);
        $this->assertStringContainsString("'evolvephp/job' => '^2.0'", $caseHBlock);
    }

    public function testConsumerValidatorClonesDisposableTaggedRepositoriesWithoutInheritedTags(): void
    {
        $content = $this->readProjectFile('tools/validate-prerelease-consumers.php');

        $this->assertSame(
            1,
            preg_match(
                '/private function createTaggedRepositories.*?git\', \'clone\', \'--no-hardlinks\', \'--no-tags\', \$splitRoot, \$repository.*?git\', \'-C\', \$repository, \'tag\', \$tag/s',
                $content
            ),
            'Disposable tagged repositories must clone split roots without source tags before creating synthetic fixture tags.'
        );
        $this->assertDoesNotMatchRegularExpression('/\\b(?:git push|remote add|config --global)\\b/i', $content);
        $this->assertDoesNotMatchRegularExpression('/\\b(?:shell_exec|exec|passthru|system)\\s*\\(/', $content);
    }

    public function testVendorQualifiedPackagesRemainInOfflineRuntimeCatalogue(): void
    {
        require_once $this->path('tools/release-validation-common.php');

        foreach (array('php', 'hhvm', 'php-64bit', 'php-ipv6', 'ext-fileinfo', 'lib-curl', 'composer-plugin-api', 'composer-runtime-api') as $name) {
            $this->assertTrue(isPlatformPackageName($name), $name . ' must remain a platform requirement.');
        }

        foreach (array('php-http/discovery', 'composer-tools/example', 'ext-tools/example', 'lib-tools/example') as $name) {
            $this->assertFalse(isPlatformPackageName($name), $name . ' is a vendor-qualified Composer package.');
        }

        $this->assertTrue(isReleaseExternalPackageName('php-http/discovery'));

        $lock = $this->readJsonFile('composer.lock');
        $locked = array_merge(
            $this->lockedPackagesByName($lock['packages']),
            $this->lockedPackagesByName($lock['packages-dev'])
        );
        $fixtures = loadLockedRuntimePackageRepositoryPackages($this->root);
        $fixtureNames = array_column($fixtures, 'name');
        $fixturesByName = array_column($fixtures, null, 'name');

        foreach (array('mcp/sdk', 'php-http/discovery') as $name) {
            $this->assertArrayHasKey($name, $locked, $name . ' must exist in composer.lock.');
            $this->assertContains($name, $fixtureNames, $name . ' must be available to offline consumers.');
            $this->assertSame($locked[$name]['version'], $fixturesByName[$name]['version'], $name . ' must retain its locked version.');
        }

        foreach (array('php', 'hhvm', 'php-64bit', 'php-ipv6', 'ext-fileinfo', 'lib-curl', 'composer-plugin-api', 'composer-runtime-api') as $name) {
            $this->assertNotContains($name, $fixtureNames, $name . ' must not be an offline package repository entry.');
        }

        $consumer = $this->readProjectFile('tools/validate-prerelease-consumers.php');
        $this->assertStringContainsString("'packagist.org' => false", $consumer);
        $this->assertStringContainsString("'COMPOSER_DISABLE_NETWORK' => '1'", $consumer);
    }

    public function testConsumerValidatorUsesLockedRuntimePackagesForOfflineThirdPartyResolution(): void
    {
        require_once $this->path('tools/release-validation-common.php');

        $this->assertTrue(
            function_exists('loadLockedRuntimePackageRepositoryPackages'),
            'release-validation-common.php must expose lockfile-derived runtime package repository metadata.'
        );

        $fixtures = loadLockedRuntimePackageRepositoryPackages($this->root);
        $lock = $this->readJsonFile('composer.lock');
        $runtimePackages = $this->lockedPackagesByName($lock['packages']);
        $devPackages = $this->lockedPackagesByName($lock['packages-dev']);
        $lockedPackages = array_merge($runtimePackages, $devPackages);
        $fixtureNames = array_column($fixtures, 'name');
        $sortedFixtureNames = $fixtureNames;

        sort($sortedFixtureNames);

        $this->assertSame($sortedFixtureNames, $fixtureNames, 'Offline runtime package metadata must be sorted deterministically.');
        $this->assertArrayHasKey('psr/http-client', $devPackages, 'The PSR-18 interface package exercises runtime closure metadata resolved from packages-dev.');
        $this->assertContains('psr/http-client', $fixtureNames, 'PSR-18 must be available to offline consumers because bridge-remote requires it at runtime.');

        foreach ($this->directExternalRuntimeRequirements() as $packageName) {
            $this->assertContains($packageName, $fixtureNames, $packageName . ' must be available to offline consumers.');
        }

        $this->assertContains('open-telemetry/api', $fixtureNames, 'Observe requires the OpenTelemetry API at runtime.');
        $this->assertContains('open-telemetry/sem-conv', $fixtureNames, 'Observe uses stable OpenTelemetry semantic convention constants at runtime.');
        $this->assertNotContains('open-telemetry/sdk', $fixtureNames, 'Observe suggesting the SDK must not pull it into published runtime closure metadata.');

        foreach ($fixtures as $fixture) {
            $this->assertIsString($fixture['name']);
            $this->assertNotSame('', $fixture['name']);
            $this->assertIsString($fixture['version']);
            $this->assertNotSame('', $fixture['version']);
            $this->assertFalse(str_starts_with($fixture['name'], 'evolvephp/'), $fixture['name'] . ' must not be supplied from lockfile metadata.');
            $this->assertFalse($this->isPlatformRequirement($fixture['name']), $fixture['name'] . ' must not be supplied as a package repository entry.');
            $this->assertArrayHasKey($fixture['name'], $lockedPackages, $fixture['name'] . ' must come from composer.lock packages or packages-dev.');
            $this->assertSame($lockedPackages[$fixture['name']]['version'], $fixture['version'], $fixture['name'] . ' must keep the locked version.');
            $this->assertTrue(
                isset($fixture['source']) || isset($fixture['dist']),
                $fixture['name'] . ' must contain source or dist metadata for the offline package repository.'
            );
        }

        foreach (array('deptrac/deptrac', 'friendsofphp/php-cs-fixer', 'phpstan/phpstan') as $packageName) {
            $this->assertNotContains($packageName, $fixtureNames, $packageName . ' must not be exposed unless it is in a release-package runtime dependency closure.');
        }

        $content = $this->readProjectFile('tools/validate-prerelease-consumers.php');

        $this->assertStringContainsString("'type' => 'package'", $content);
        $this->assertStringContainsString("'package' => \$lockedRuntimePackages", $content);
        $this->assertStringContainsString("'packagist.org' => false", $content);
        $this->assertStringContainsString("'COMPOSER_DISABLE_NETWORK' => '1'", $content);
    }

    public function testSharedReleasePackageLoaderAcceptsCanonicalPackageMap(): void
    {
        require_once $this->path('tools/release-validation-common.php');

        $packages = loadReleasePackages($this->root);
        $map = $this->readJsonFile('release-packages.json');
        $packageNames = array_column($packages, 'name');
        $coreIndex = array_search('evolvephp/core', $packageNames, true);

        $this->assertIsInt($coreIndex);
        $this->assertCount(34, $packages);
        $this->assertContains('evolvephp/insight', $packageNames);
        $this->assertSame(
            array('name' => 'evolvephp/database-contracts', 'directory' => 'packages/database-contracts'),
            $packages[1]
        );
        $this->assertSame(
            array('name' => 'evolvephp/database-pdo', 'directory' => 'packages/database-pdo'),
            $packages[2]
        );
        $this->assertSame(
            array('name' => 'evolvephp/cache-memory', 'directory' => 'packages/cache-memory'),
            $packages[3]
        );
        $this->assertSame(
            array('name' => 'evolvephp/session-contracts', 'directory' => 'packages/session-contracts'),
            $packages[4]
        );
        $this->assertSame(
            array('name' => 'evolvephp/lock-contracts', 'directory' => 'packages/lock-contracts'),
            $packages[5]
        );
        $this->assertSame(
            array('name' => 'evolvephp/queue-contracts', 'directory' => 'packages/queue-contracts'),
            $packages[6]
        );
        $this->assertSame(
            array('name' => 'evolvephp/queue-memory', 'directory' => 'packages/queue-memory'),
            $packages[7]
        );
        $this->assertSame(
            array('name' => 'evolvephp/storage-contracts', 'directory' => 'packages/storage-contracts'),
            $packages[8]
        );
        $this->assertSame(
            array('name' => 'evolvephp/storage-local', 'directory' => 'packages/storage-local'),
            $packages[9]
        );
        $this->assertSame(
            array('name' => 'evolvephp/secret-contracts', 'directory' => 'packages/secret-contracts'),
            $packages[10]
        );
        $this->assertSame(
            array('name' => 'evolvephp/job', 'directory' => 'packages/job'),
            $packages[$coreIndex + 1]
        );
        $this->assertSame(
            array('name' => 'evolvephp/scheduler', 'directory' => 'packages/scheduler'),
            $packages[$coreIndex + 2]
        );
        $this->assertSame(
            array('name' => 'evolvephp/migration', 'directory' => 'packages/migration'),
            $packages[$coreIndex + 3]
        );
        $this->assertSame(
            array('name' => 'evolvephp/view', 'directory' => 'packages/view'),
            $packages[$coreIndex + 4]
        );
        $this->assertSame(array('name' => 'evolvephp/view-twig', 'directory' => 'packages/view-twig'), $packages[$coreIndex + 5]);
        $this->assertSame(array('name' => 'evolvephp/view-blade', 'directory' => 'packages/view-blade'), $packages[$coreIndex + 6]);
        $this->assertSame(array('name' => 'evolvephp/i18n', 'directory' => 'packages/i18n'), $packages[$coreIndex + 7]);
        $this->assertSame(
            array('name' => 'evolvephp/insight', 'directory' => 'packages/insight'),
            $packages[$coreIndex + 11]
        );
        $this->assertSame(
            array('name' => 'evolvephp/observe', 'directory' => 'packages/observe'),
            $packages[$coreIndex + 13]
        );
        $this->assertSame(array('name' => 'evolvephp/mcp', 'directory' => 'packages/mcp'), $packages[$coreIndex + 14]);
        $this->assertSame(array('name' => 'evolvephp/ai', 'directory' => 'packages/ai'), $packages[$coreIndex + 15]);
        $this->assertSame($map['packages'], $packages);
    }

    public function testSkeletonValidatorUsesMinimalLockedOfflineVendorClosure(): void
    {
        require_once $this->path('tools/release-validation-common.php');

        $this->assertTrue(
            function_exists('loadSkeletonLockedPackageRepositoryPackages'),
            'release-validation-common.php must expose skeleton-specific lockfile package metadata.'
        );

        $fixtures = loadSkeletonLockedPackageRepositoryPackages($this->root);
        $fixtureNames = array_column($fixtures, 'name');
        $sortedFixtureNames = $fixtureNames;

        sort($sortedFixtureNames);

        $this->assertSame($sortedFixtureNames, $fixtureNames, 'Skeleton offline package metadata must be sorted deterministically.');

        foreach (array('phpunit/phpunit', 'psr/container', 'psr/http-message') as $packageName) {
            $this->assertContains($packageName, $fixtureNames, $packageName . ' must be available to offline skeleton validation.');
        }

        foreach (array('deptrac/deptrac', 'friendsofphp/php-cs-fixer', 'phpstan/phpstan') as $packageName) {
            $this->assertNotContains($packageName, $fixtureNames, $packageName . ' must not be copied into the skeleton validator repository.');
        }

        $this->assertLessThan(
            count($this->lockedPackagesByName($this->readJsonFile('composer.lock')['packages-dev'])),
            count($fixtureNames),
            'Skeleton validation should not expose every dev package from the root vendor directory.'
        );
    }

    public function testSkeletonValidatorDocumentsRealOfflineCreateProjectContract(): void
    {
        $content = $this->readProjectFile('tools/validate-skeleton-project.php');

        foreach (array(
            'captureSourceState',
            'assertSourceStatePreserved',
            'createTemporaryDirectory',
            'loadSkeletonLockedPackageRepositoryPackages',
            'create-project',
            'COMPOSER_DISABLE_NETWORK',
            'packagist.org',
            'symlink',
            'composer validate --strict',
            'bin/evolve',
            'doctor',
            'route:list',
            'No routes are configured.',
            'No command was specified.',
            'Command "missing" was not found.',
            'The route:list command does not accept arguments or options.',
            'module:new',
            'plugin:new',
            'composer install --no-dev',
            'Source repository state preserved.',
        ) as $needle) {
            $this->assertStringContainsString($needle, $content);
        }

        foreach (array(
            '[1/13] Preparing offline repositories',
            '[2/13] Running Composer create-project',
            '[3/13] Validating generated manifest',
            '[4/13] Validating installed packages',
            '[5/13] Running generated Doctor',
            '[6/13] Running generated route:list',
            '[7/13] Running generated module:new',
            '[8/13] Running generated plugin:new',
            '[9/13] Running generated test suite',
            '[10/13] Running collision and traversal checks',
            '[11/13] Running Composer install --no-dev',
            '[12/13] Running no-dev Doctor and route:list',
            '[13/13] Cleaning up and preserving source state',
        ) as $stage) {
            $this->assertStringContainsString($stage, $content);
        }

        $this->assertStringContainsString('loadSkeletonLockedPackageRepositoryPackages', $content);
        $this->assertStringContainsString('prepareOfflineVendorRepository', $content);
        $this->assertStringNotContainsString('joinPaths($root, \'vendor/*/*\')', $content);
        $this->assertDoesNotMatchRegularExpression('/\\b(?:curl|gh|git push|remote add|config --global|shell_exec|exec|passthru|system)\\b/i', $content);
        $this->assertDoesNotMatchRegularExpression('/\\b(?:robocopy|xcopy)\\b/i', $content);
    }

    public function testWorkspaceComposerExposesReleaseValidationScriptsWithoutPrepareScript(): void
    {
        $manifest = $this->readJsonFile('composer.json');

        $this->assertSame(array('@architecture', '@analyse', '@style:check', '@test'), $manifest['scripts']['quality']);
        $this->assertSame(array('@security:audit', '@licenses:check'), $manifest['scripts']['supply-chain']);
        $this->assertSame('@php tools/validate-release-packages.php', $manifest['scripts']['release:validate']);
        $this->assertSame('@php tools/validate-package-splits.php', $manifest['scripts']['release:split:validate']);
        $this->assertSame('@php tools/validate-prerelease-consumers.php', $manifest['scripts']['release:consumer:validate']);
        $this->assertSame('@php tools/validate-skeleton-project.php', $manifest['scripts']['release:skeleton:validate']);
        $this->assertArrayNotHasKey('release:prepare', $manifest['scripts']);
    }

    public function testPackageManifestsRetainStableInternalConstraintsAndNoStabilityPolicy(): void
    {
        foreach ($this->releasePackages() as $package) {
            $manifest = $this->readJsonFile($package['directory'] . '/composer.json');

            $this->assertArrayNotHasKey('version', $manifest, $package['name']);
            $this->assertArrayNotHasKey('minimum-stability', $manifest, $package['name']);
            $this->assertArrayNotHasKey('prefer-stable', $manifest, $package['name']);
            $this->assertSame('^8.4', $manifest['require']['php'], $package['name']);

            foreach ($manifest['require'] as $dependency => $constraint) {
                if (strpos($dependency, 'evolvephp/') !== 0) {
                    continue;
                }

                $this->assertSame('^2.0', $constraint, $package['name'] . ' internal constraint for ' . $dependency);
                $this->assertStringNotContainsString('@alpha', $constraint, $package['name']);
            }
        }
    }

    public function testCiRunsPackageSplitAndSkeletonValidationInExistingPolicyJob(): void
    {
        $workflow = $this->readProjectFile('.github/workflows/quality.yml');

        $this->assertSame(1, substr_count($workflow, 'name: Policy (PHP 8.4)'));
        $this->assertSame(1, substr_count($workflow, 'name: Workspace quality (PHP ${{ matrix.php }})'));
        $this->assertSame(1, substr_count($workflow, 'Run release package split validation'));
        $this->assertSame(1, substr_count($workflow, 'Run prerelease consumer validation'));
        $this->assertSame(1, substr_count($workflow, 'Run application skeleton create-project validation'));
        $this->assertSame(1, substr_count($workflow, 'composer release:split:validate'));
        $this->assertSame(1, substr_count($workflow, 'composer release:consumer:validate'));
        $this->assertSame(1, substr_count($workflow, 'composer release:skeleton:validate'));
        $this->assertStringContainsString('Run root supply-chain checks', $workflow);
        $this->assertStringContainsString('Run root policy tests', $workflow);
    }

    public function testWorkspaceReadmeDocumentsAlphaConsumerPolicyAndDeferredPublication(): void
    {
        $content = $this->readProjectFile('DEVELOPMENT.md');

        foreach (array(
            'composer release:split:validate',
            'composer release:consumer:validate',
            'composer release:skeleton:validate',
            'skeleton/',
            'evolvephp/skeleton',
            'public experimental',
            'CliApplication',
            'StreamCommandOutput',
            'application CLI composition is explicit',
            'Core remains independent of HTTP',
            'Packagist create-project availability is not yet claimed',
            'minimum-stability: alpha',
            'prefer-stable: true',
            'Explicit root `@alpha` flags',
            'no package Composer manifest should add `@alpha`, `minimum-stability`, `prefer-stable` or a hard-coded `version` field.',
            'Remote package repositories, remote synchronization, Packagist registration, tags and releases remain deferred.',
        ) as $needle) {
            $this->assertStringContainsString($needle, $content);
        }
    }

    public function testChangelogRecordsPhase210BValidationWithoutPublicationClaims(): void
    {
        $content = $this->readProjectFile('CHANGELOG.md');

        $this->assertStringContainsString('Phase 2.10B deterministic package split and prerelease consumer validation', $content);
        $this->assertStringContainsString('alpha consumer policy', $content);
        $this->assertDoesNotMatchRegularExpression('/Phase 2\\.10B.*(?:published|Packagist|GitHub release)/i', $content);
    }

    private function readProjectFile($path)
    {
        $absolute = $this->path($path);

        $this->assertFileExists($absolute, $path . ' must exist.');

        $content = file_get_contents($absolute);

        $this->assertIsString($content, $path . ' must be readable.');

        return $content;
    }

    private function readJsonFile($path)
    {
        $decoded = json_decode($this->readProjectFile($path), true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), $path . ' must contain valid JSON.');
        $this->assertIsArray($decoded, $path . ' must decode to an array.');

        return $decoded;
    }

    private function initializeGitFixture($runner, $path)
    {
        $runner->mustRun(array('git', 'init'), $path);
        $runner->mustRun(array('git', 'config', 'user.name', 'EvolvePHP Test'), $path);
        $runner->mustRun(array('git', 'config', 'user.email', 'evolvephp-test@example.com'), $path);
        $runner->mustRun(array('git', 'config', 'core.quotepath', 'false'), $path);
    }

    private function writeFixtureFile($root, $relativePath, $contents)
    {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($path);

        if (!is_dir($directory)) {
            $this->assertTrue(mkdir($directory, 0777, true), 'Fixture directory must be created: ' . $relativePath);
        }

        $this->assertIsInt(file_put_contents($path, $contents), 'Fixture file must be writable: ' . $relativePath);
    }

    private function classifyCommittedFixturePaths($paths)
    {
        $runner = new ReleaseValidationProcessRunner();
        $temporary = createTemporaryDirectory('evolvephp-doc-classification-');
        $fixture = $temporary->path;

        try {
            $this->initializeGitFixture($runner, $fixture);
            $this->writeFixtureFile($fixture, 'README.md', "base\n");

            $runner->mustRun(array('git', 'add', 'README.md'), $fixture);
            $runner->mustRun(array('git', 'commit', '-m', 'Base'), $fixture);
            $base = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $fixture)->stdout);

            foreach ($paths as $path => $contents) {
                $this->writeFixtureFile($fixture, $path, $contents);
            }

            $runner->mustRun(array('git', 'add', '.'), $fixture);
            $runner->mustRun(array('git', 'commit', '-m', 'Changed paths'), $fixture);
            $head = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $fixture)->stdout);
            $changedPaths = packageSplitChangedPaths($runner, $fixture, $base, $head);

            return decidePackageSplitValidationScope(loadReleasePackages($this->root), $changedPaths);
        } finally {
            $temporary->cleanup();
        }
    }

    private function releasePackages()
    {
        $map = $this->readJsonFile('release-packages.json');

        $this->assertSame(1, $map['version']);
        $this->assertCount(34, $map['packages']);

        return $map['packages'];
    }

    private function directExternalRuntimeRequirements()
    {
        $requirements = array();

        foreach ($this->releasePackages() as $package) {
            $manifest = $this->readJsonFile($package['directory'] . '/composer.json');

            foreach ($manifest['require'] as $packageName => $constraint) {
                if (str_starts_with($packageName, 'evolvephp/') || $this->isPlatformRequirement($packageName)) {
                    continue;
                }

                $requirements[$packageName] = true;
            }
        }

        $names = array_keys($requirements);
        sort($names);

        return $names;
    }

    private function lockedPackagesByName($packages)
    {
        $this->assertIsArray($packages);

        $locked = array();

        foreach ($packages as $package) {
            $this->assertIsArray($package);
            $this->assertArrayHasKey('name', $package);
            $this->assertArrayHasKey('version', $package);

            $locked[$package['name']] = $package;
        }

        ksort($locked);

        return $locked;
    }

    private function isPlatformRequirement($packageName)
    {
        return $packageName === 'php'
            || str_starts_with($packageName, 'ext-')
            || str_starts_with($packageName, 'lib-');
    }

    private function path($path)
    {
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
