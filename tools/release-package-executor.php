<?php declare(strict_types=1);

require_once __DIR__ . '/release-package-publisher.php';

/** Offline pilot preflight. It does not establish remote ownership or authorize writes. */
final class ReleasePackageExecutorFailure extends RuntimeException
{
}

final class ReleasePackageExecutor
{
    private const PILOT = 'evolvephp/contracts';
    private const DIRECTORY = 'packages/contracts';
    private const DESTINATION = 'josiahking/evolvephp-contracts';
    private const TAG_REF = 'refs/tags/2.0.0-beta.1';

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed>|null $evidence
     * @return array<string, mixed>
     */
    public function prepare(array $plan, string $root, ?array $evidence = null): array
    {
        if (!isset($plan['plan_sha256']) || !is_string($plan['plan_sha256'])
            || !preg_match('/^[a-f0-9]{64}$/D', $plan['plan_sha256'])) {
            $this->fail('missing-or-invalid-plan-digest');
        }
        $digest = $plan['plan_sha256'];
        unset($plan['plan_sha256']);
        if (!hash_equals(hash('sha256', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), $digest)) {
            $this->fail('plan-digest-mismatch');
        }
        if (($plan['version'] ?? null) !== 1 || ($plan['mode'] ?? null) !== 'dry-run'
            || ($plan['publication_enabled'] ?? null) !== false
            || ($plan['pilot'] ?? null) !== self::PILOT) {
            $this->fail('unsupported-plan');
        }
        if (isset($plan['token']) || isset($plan['remote_state'])
            || !in_array($plan['remote_observation'] ?? 'not-inspected',
                array('not-inspected', 'reported-absent-unverified'), true)) {
            $this->fail('remote-state-unverified-or-ambiguous');
        }
        $source = $plan['source'] ?? null;
        if (!is_array($source) || array_keys($source) !== array('branch', 'commit', 'tree')
            || !is_string($source['branch']) || !is_string($source['commit']) || !is_string($source['tree'])
            || !preg_match('/^[a-f0-9]{40}$/D', $source['commit'])
            || !preg_match('/^[a-f0-9]{40}$/D', $source['tree'])) {
            $this->fail('missing-or-invalid-source-pins');
        }
        $packages = $plan['packages'] ?? null;
        if (!is_array($packages) || !array_is_list($packages)
            || count($packages) !== 34) {
            $this->fail('invalid-package-inventory');
        }
        $pilot = $packages[0];
        if (!is_array($pilot)
            || ($pilot['name'] ?? null) !== self::PILOT
            || ($pilot['directory'] ?? null) !== self::DIRECTORY
            || ($pilot['destination'] ?? null) !== self::DESTINATION
            || ($pilot['destination_status'] ?? null) !== 'proposed-unverified'
            || !is_string($pilot['package_tree'] ?? null)
            || !preg_match('/^[a-f0-9]{40}$/D', $pilot['package_tree'])) {
            $this->fail('pilot-identity-mismatch');
        }
        $names = array_column($packages, 'name');
        if (count(array_unique($names)) !== 34) {
            $this->fail('duplicate-package');
        }
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) {
            $this->fail('source-checkout-unavailable');
        }
        if (str_replace('\\', '/', $this->git($resolved, array('rev-parse', '--show-toplevel'))) !== str_replace('\\', '/', $resolved)
            || $this->git($resolved, array('rev-parse', '--abbrev-ref', 'HEAD')) !== $source['branch']
            || $this->git($resolved, array('rev-parse', '--verify', 'HEAD^{commit}')) !== $source['commit']
            || $this->git($resolved, array('rev-parse', '--verify', 'HEAD^{tree}')) !== $source['tree']) {
            $this->fail('source-identity-mismatch');
        }
        if ($this->git($resolved, array('rev-parse', '--verify', 'HEAD:' . self::DIRECTORY)) !== $pilot['package_tree']) {
            $this->fail('package-tree-mismatch');
        }
        // Regenerate the publisher's exact plan from the committed, clean checkout.
        // A caller can recompute SHA-256; that alone proves no source provenance.
        try {
            $canonical = (new PackagePublisherPreparation())->plan(array(
                '--root=' . $resolved,
                '--source-branch=' . $source['branch'],
                '--source-commit=' . $source['commit'],
                '--source-tree=' . $source['tree'],
            ));
        } catch (Throwable) {
            $this->fail('source-provenance-rejected');
        }
        $submitted = $plan;
        $submitted['plan_sha256'] = $digest;
        if ($submitted !== $canonical) {
            $this->fail('noncanonical-publisher-plan');
        }
        $splitCommit = $tagObject = $tagCommit = $remoteCommit = null;
        if ($evidence !== null) {
            if (array_keys($evidence) !== array('package', 'destination', 'split_tree', 'split_commit',
                'remote_commit', 'tag_ref', 'tag_object', 'tag_commit')) {
                $this->fail('invalid-evidence-shape');
            }
            if ($evidence['package'] !== self::PILOT || $evidence['destination'] !== self::DESTINATION
                || $evidence['tag_ref'] !== self::TAG_REF) {
                $this->fail('evidence-identity-mismatch');
            }
            $ids = array($evidence['split_tree'], $evidence['split_commit'], $evidence['remote_commit'],
                $evidence['tag_object'], $evidence['tag_commit']);
            $present = array_filter($ids, static fn ($id): bool => $id !== null);
            if ($present !== array() && count($present) !== 5) {
                $this->fail('partial-publication-state');
            }
            if ($present !== array()) {
                foreach ($ids as $id) {
                    if (!is_string($id) || !preg_match('/^[a-f0-9]{40}$/D', $id)) {
                        $this->fail('invalid-object-identity');
                    }
                }
                if ($evidence['split_tree'] !== $pilot['package_tree']
                    || $evidence['remote_commit'] !== $evidence['split_commit']
                    || $evidence['tag_commit'] !== $evidence['split_commit']) {
                    $this->fail('split-remote-tag-mismatch');
                }
                if ($this->git($resolved, array('cat-file', '-t', $evidence['split_commit'])) !== 'commit'
                    || $this->git($resolved, array('rev-parse', $evidence['split_commit'] . '^{tree}'))
                        !== $pilot['package_tree']) {
                    $this->fail('split-commit-tree-mismatch');
                }
                [$splitCommit, $remoteCommit, $tagObject, $tagCommit] = array(
                    $evidence['split_commit'], $evidence['remote_commit'],
                    $evidence['tag_object'], $evidence['tag_commit']);
            }
        }
        return array(
            'version' => 1, 'mode' => 'offline-pilot-preflight', 'publication_enabled' => false,
            'status' => 'blocked-pending-independent-authorization',
            'source' => $source, 'plan_sha256' => $digest,
            'package' => self::PILOT, 'directory' => self::DIRECTORY,
            'package_tree' => $pilot['package_tree'], 'destination' => self::DESTINATION,
            'destination_ownership' => 'unverified', 'tag_ref' => self::TAG_REF,
            'split_commit' => $splitCommit, 'remote_commit' => $remoteCommit,
            'tag_object' => $tagObject, 'tag_commit' => $tagCommit,
            'remote_state' => 'unverified',
            'blockers' => array('ownership-proof-missing', 'remote-state-unverified',
                'independent-split-and-tag-proof-missing', 'execution-authorization-missing'),
        );
    }

    /** @param list<string> $arguments */
    private function git(string $root, array $arguments): string
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $environment = array('GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => $null,
            'GIT_TERMINAL_PROMPT' => '0', 'GIT_ALLOW_PROTOCOL' => '', 'GIT_NO_LAZY_FETCH' => '1');
        foreach (array('PATH', 'SystemRoot', 'WINDIR', 'SYSTEMDRIVE') as $key) {
            $value = getenv($key);
            if ($value !== false) { $environment[$key] = $value; }
        }
        $command = array_merge(array('git', '--no-optional-locks', '--no-replace-objects',
            '-c', 'core.fsmonitor=false', '-c', 'protocol.allow=never', '-C', $root), $arguments);
        $process = proc_open($command, array(0 => array('file', $null, 'r'),
            1 => array('pipe', 'w'), 2 => array('file', $null, 'w')),
            $pipes, null, $environment, array('bypass_shell' => true));
        if (!is_resource($process)) { $this->fail('read-only-git-inspection-failed'); }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0 || $output === false) {
            $this->fail('read-only-git-inspection-failed');
        }
        return rtrim($output, "\r\n");
    }

    private function fail(string $status): never
    {
        throw new ReleasePackageExecutorFailure($status);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $options = array();
        foreach (array_slice($argv, 1) as $argument) {
            if (!preg_match('/^--(plan|root)=([^\x00-\x1f\x7f]+)$/D', $argument, $match)
                || isset($options[$match[1]])) {
                throw new ReleasePackageExecutorFailure('unsupported-or-duplicate-option');
            }
            $options[$match[1]] = $match[2];
        }
        if (!isset($options['plan']) || !is_file($options['plan'])
            || is_link($options['plan']) || filesize($options['plan']) > 1048576) {
            throw new ReleasePackageExecutorFailure('missing-or-unsafe-plan-file');
        }
        $bytes = file_get_contents($options['plan']);
        if ($bytes === false) { throw new ReleasePackageExecutorFailure('plan-read-failed'); }
        $plan = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($plan)) { throw new ReleasePackageExecutorFailure('invalid-plan'); }
        $result = (new ReleasePackageExecutor())->prepare($plan, $options['root'] ?? dirname(__DIR__));
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (Throwable $failure) {
        $status = $failure instanceof ReleasePackageExecutorFailure ? $failure->getMessage() : 'invalid-input';
        fwrite(STDERR, 'EvolvePHP package executor failed: ' . $status . PHP_EOL);
        exit(1);
    }
}