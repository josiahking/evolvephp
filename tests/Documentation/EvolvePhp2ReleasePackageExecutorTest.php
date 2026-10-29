<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tools/release-validation-common.php';
require_once dirname(__DIR__, 2) . '/tools/release-package-publisher.php';
require_once dirname(__DIR__, 2) . '/tools/release-package-executor.php';

final class EvolvePhp2ReleasePackageExecutorTest extends TestCase
{
    private static ReleaseValidationTemporaryDirectory $fixture;
    /** @var array<string, mixed> */
    private static array $plan;

    public static function setUpBeforeClass(): void
    {
        $source = dirname(__DIR__, 2);
        self::$fixture = createTemporaryDirectory('evolvephp-executor-test-');
        $root = self::$fixture->path;
        $runner = new ReleaseValidationProcessRunner();
        $runner->mustRun(array('git', 'init'), $root);
        $runner->mustRun(array('git', 'config', 'user.name', 'Release Fixture'), $root);
        $runner->mustRun(array('git', 'config', 'user.email', 'fixture@example.invalid'), $root);
        $runner->mustRun(array('git', 'checkout', '-b', 'feature/executor-fixture'), $root);
        $files = array('LICENSE.md', 'release-packages.json', 'tools/release-package-destinations.json');
        $inventory = json_decode((string) file_get_contents($source . '/release-packages.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($inventory['packages'] as $package) {
            foreach (array('composer.json', 'LICENSE.md', 'README.md') as $name) {
                $files[] = $package['directory'] . '/' . $name;
            }
        }
        foreach ($files as $path) {
            $target = self::$fixture->child($path);
            if (!is_dir(dirname($target))) { mkdir(dirname($target), 0777, true); }
            copy($source . '/' . $path, $target);
        }
        $runner->mustRun(array('git', 'add', '.'), $root);
        $runner->mustRun(array('git', 'commit', '-m', 'Committed publisher fixture'), $root);
        $commit = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD'), $root)->stdout);
        $tree = trim($runner->mustRun(array('git', 'rev-parse', 'HEAD^{tree}'), $root)->stdout);
        self::$plan = (new PackagePublisherPreparation())->plan(array(
            '--root=' . $root, '--source-branch=feature/executor-fixture',
            '--source-commit=' . $commit, '--source-tree=' . $tree,
        ));
    }

    public static function tearDownAfterClass(): void
    {
        self::$fixture->cleanup();
    }

    public function testAuthenticCanonicalPublisherPlanIsAcceptedButPublicationBlocked(): void
    {
        $before = $this->state();
        $first = (new ReleasePackageExecutor())->prepare(self::$plan, self::$fixture->path);
        $this->assertSame($first, (new ReleasePackageExecutor())->prepare(self::$plan, self::$fixture->path));
        $this->assertCount(34, self::$plan['packages']);
        $this->assertFalse($first['publication_enabled']);
        $this->assertSame('unverified', $first['destination_ownership']);
        $this->assertSame('unverified', $first['remote_state']);
        $this->assertSame('refs/tags/2.0.0-beta.1', $first['tag_ref']);
        $this->assertSame($before, $this->state());
    }

    public function testForgedSinglePackageAndFabricatedFullInventoryAreRejected(): void
    {
        $single = self::$plan;
        $single['packages'] = array($single['packages'][0]);
        $this->reject($single);
        $fabricated = self::$plan;
        $fabricated['credential_policy'] = 'caller-generated';
        $this->reject($fabricated);
    }

    public function testIncorrectOrderAndCommittedMetadataAreRejected(): void
    {
        $order = self::$plan;
        [$order['packages'][0], $order['packages'][1]] = [$order['packages'][1], $order['packages'][0]];
        $this->reject($order);
        foreach (array('package_tree', 'composer_blob', 'license_blob', 'destination') as $field) {
            $wrong = self::$plan;
            $wrong['packages'][1][$field] = str_repeat('a', 40);
            $this->reject($wrong);
        }
        $wrong = self::$plan;
        $wrong['source']['tree'] = str_repeat('0', 40);
        $this->reject($wrong);
    }

    public function testDirtyTrackedAndUntrackedSourceAreRejectedWithoutMutation(): void
    {
        $root = self::$fixture->path;
        $untracked = self::$fixture->child('untracked.txt');
        file_put_contents($untracked, 'untracked');
        try {
            $before = $this->state();
            $this->reject(self::$plan);
            $this->assertSame($before, $this->state());
        } finally {
            unlink($untracked);
        }
        $tracked = self::$fixture->child('packages/contracts/README.md');
        $original = (string) file_get_contents($tracked);
        file_put_contents($tracked, $original . "\nmodified");
        try {
            $before = $this->state();
            $this->reject(self::$plan);
            $this->assertSame($before, $this->state());
        } finally {
            file_put_contents($tracked, $original);
        }
    }

    public function testHiddenIndexEntryIsRejectedWithoutMutation(): void
    {
        $runner = new ReleaseValidationProcessRunner();
        $root = self::$fixture->path;
        $path = 'packages/contracts/README.md';
        $runner->mustRun(array('git', 'update-index', '--assume-unchanged', $path), $root);
        try {
            $before = $this->state();
            $this->reject(self::$plan);
            $this->assertSame($before, $this->state());
        } finally {
            $runner->mustRun(array('git', 'update-index', '--no-assume-unchanged', $path), $root);
        }
    }

    public function testUnmergedIndexIsRejectedWithoutMutation(): void
    {
        $temporary = createTemporaryDirectory('evolvephp-executor-unmerged-');
        $clone = $temporary->child('clone');
        $runner = new ReleaseValidationProcessRunner();
        try {
            $runner->mustRun(array('git', 'clone', '--local', '--no-hardlinks',
                self::$fixture->path, $clone));
            $blob = trim($runner->mustRun(array('git', 'rev-parse',
                'HEAD:packages/contracts/README.md'), $clone)->stdout);
            $path = 'packages/contracts/README.md';
            $input = "0 " . str_repeat('0', 40) . "\t" . $path . "\n"
                . "100644 " . $blob . " 1\t" . $path . "\n"
                . "100644 " . $blob . " 2\t" . $path . "\n";
            $process = proc_open(array('git', '-C', $clone, 'update-index', '--index-info'),
                array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'),
                    2 => array('pipe', 'w')), $pipes);
            $this->assertIsResource($process);
            fwrite($pipes[0], $input);
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process));
            $this->assertNotSame('', trim($runner->mustRun(array('git', 'ls-files', '-u'), $clone)->stdout));
            $before = array(
                'head' => $runner->mustRun(array('git', 'rev-parse', 'HEAD'), $clone)->stdout,
                'refs' => $runner->mustRun(array('git', 'for-each-ref'), $clone)->stdout,
                'index' => hash_file('sha256', $clone . '/.git/index'),
            );
            try {
                (new ReleasePackageExecutor())->prepare(self::$plan, $clone);
                $this->fail('Expected unmerged-index rejection.');
            } catch (ReleasePackageExecutorFailure $failure) {
                $this->assertNotSame('', $failure->getMessage());
            }
            $this->assertSame($before, array(
                'head' => $runner->mustRun(array('git', 'rev-parse', 'HEAD'), $clone)->stdout,
                'refs' => $runner->mustRun(array('git', 'for-each-ref'), $clone)->stdout,
                'index' => hash_file('sha256', $clone . '/.git/index'),
            ));
        } finally {
            $temporary->cleanup();
        }
    }

    public function testEvidenceRemainsUnverifiedAndPartialEvidenceFailsClosed(): void
    {
        $empty = array('package' => 'evolvephp/contracts', 'destination' => 'josiahking/evolvephp-contracts',
            'split_tree' => null, 'split_commit' => null, 'remote_commit' => null,
            'tag_ref' => 'refs/tags/2.0.0-beta.1', 'tag_object' => null, 'tag_commit' => null);
        $result = (new ReleasePackageExecutor())->prepare(self::$plan, self::$fixture->path, $empty);
        $this->assertNull($result['split_commit']);
        $this->assertFalse($result['publication_enabled']);
        $partial = $empty; $partial['split_commit'] = str_repeat('a', 40);
        $this->reject(self::$plan, $partial);
    }

    public function testPlanPilotDestinationAndInjectedFieldsFailClosed(): void
    {
        $before = $this->state();
        $wrongPilot = self::$plan;
        $wrongPilot['pilot'] = 'evolvephp/core';
        $this->reject($wrongPilot, null, 'unsupported-plan');

        foreach (array(
            'name' => 'evolvephp/core',
            'directory' => 'packages/core',
            'destination' => 'attacker/evolvephp-contracts',
            'destination_status' => 'verified',
        ) as $field => $value) {
            $wrong = self::$plan;
            $wrong['packages'][0][$field] = $value;
            $this->reject($wrong, null, 'pilot-identity-mismatch');
        }

        $injectedToken = self::$plan;
        $injectedToken['token'] = 'secret-sentinel';
        $this->reject($injectedToken, null, 'remote-state-unverified-or-ambiguous');
        $injectedRemoteState = self::$plan;
        $injectedRemoteState['remote_state'] = 'reported-absent';
        $this->reject($injectedRemoteState, null, 'remote-state-unverified-or-ambiguous');
        $this->assertSame($before, $this->state());
    }

    public function testCorruptedDigestAndInvalidSourcePinsFailClosed(): void
    {
        $before = $this->state();
        $corrupted = self::$plan;
        $corrupted['plan_sha256'] = str_repeat('0', 64);
        $this->reject($corrupted, null, 'plan-digest-mismatch', false);
        $missing = self::$plan;
        unset($missing['plan_sha256']);
        $this->reject($missing, null, 'missing-or-invalid-plan-digest', false);
        foreach (array(
            array('commit', 'HEAD', 'missing-or-invalid-source-pins'),
            array('tree', 'invalid', 'missing-or-invalid-source-pins'),
            array('commit', str_repeat('0', 40), 'source-identity-mismatch'),
        ) as [$field, $value, $status]) {
            $wrong = self::$plan;
            $wrong['source'][$field] = $value;
            $this->reject($wrong, null, $status);
        }
        $this->assertSame($before, $this->state());
    }

    public function testEvidenceTagDestinationSplitAndUnexpectedFieldsFailClosed(): void
    {
        $before = $this->state();
        $empty = array('package' => 'evolvephp/contracts',
            'destination' => 'josiahking/evolvephp-contracts',
            'split_tree' => null, 'split_commit' => null, 'remote_commit' => null,
            'tag_ref' => 'refs/tags/2.0.0-beta.1',
            'tag_object' => null, 'tag_commit' => null);

        foreach (array(
            array('tag_ref', 'refs/tags/2.0.0-beta.2'),
            array('tag_ref', '2.0.0-beta.1'),
            array('destination', 'attacker/evolvephp-contracts'),
            array('package', 'evolvephp/core'),
        ) as [$field, $value]) {
            $wrong = $empty;
            $wrong[$field] = $value;
            $this->reject(self::$plan, $wrong, 'evidence-identity-mismatch');
        }

        $complete = $empty;
        $complete['split_tree'] = self::$plan['packages'][0]['package_tree'];
        $complete['split_commit'] = self::$plan['source']['commit'];
        $complete['remote_commit'] = self::$plan['source']['commit'];
        $complete['tag_object'] = self::$plan['source']['commit'];
        $complete['tag_commit'] = self::$plan['source']['commit'];
        foreach (array(
            'split_tree' => str_repeat('a', 40),
            'remote_commit' => str_repeat('b', 40),
            'tag_commit' => str_repeat('c', 40),
        ) as $field => $value) {
            $wrong = $complete;
            $wrong[$field] = $value;
            $this->reject(self::$plan, $wrong, 'split-remote-tag-mismatch');
        }

        $duplicateTag = $empty;
        $duplicateTag['duplicate_tag_ref'] = 'refs/tags/2.0.0-beta.1';
        $this->reject(self::$plan, $duplicateTag, 'invalid-evidence-shape');
        $unexpected = $empty;
        $unexpected['unexpected_remote_field'] = 'secret-sentinel';
        $this->reject(self::$plan, $unexpected, 'invalid-evidence-shape');
        $this->assertSame($before, $this->state());
    }

    public function testDocumentationKeepsAuthorizationAndUnreleasedBoundary(): void
    {
        $root = dirname(__DIR__, 2);
        $development = file_get_contents($root . '/DEVELOPMENT.md');
        $manual = file_get_contents($root . '/website/docs/next/project/releases.md');
        $this->assertStringContainsString('Future execution requires a separately reviewed authorization artifact', $development);
        $this->assertStringContainsString('No publication path is implemented here', $development);
        $this->assertStringContainsString('Public Beta and Packagist availability remain pending', $manual);
    }

    /**
     * @param array<string, mixed> $proposal
     * @param array<string, mixed>|null $evidence
     */
    private function reject(
        array $proposal,
        ?array $evidence = null,
        ?string $expectedStatus = null,
        bool $recomputeDigest = true,
    ): void {
        if ($recomputeDigest) {
            unset($proposal['plan_sha256']);
            $proposal['plan_sha256'] = hash('sha256', json_encode($proposal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        try {
            (new ReleasePackageExecutor())->prepare($proposal, self::$fixture->path, $evidence);
            $this->fail('Expected a provenance rejection.');
        } catch (ReleasePackageExecutorFailure $failure) {
            $this->assertNotSame('', $failure->getMessage());
            $this->assertStringNotContainsString('secret-sentinel', $failure->getMessage());
            if ($expectedStatus !== null) {
                $this->assertSame($expectedStatus, $failure->getMessage());
            }
        }
    }

    /** @return array{head: string, refs: string, index: string, status: string} */
    private function state(): array
    {
        $root = self::$fixture->path;
        $runner = new ReleaseValidationProcessRunner();
        return array(
            'head' => $runner->mustRun(array('git', 'rev-parse', 'HEAD'), $root)->stdout,
            'refs' => $runner->mustRun(array('git', 'for-each-ref', '--sort=refname'), $root)->stdout,
            'index' => hash_file('sha256', $root . '/.git/index'),
            'status' => $runner->mustRun(array('git', '--no-optional-locks', 'status',
                '--porcelain=v1', '--untracked-files=all'), $root)->stdout,
        );
    }
}