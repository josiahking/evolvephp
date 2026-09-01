<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Tests\Comparator;

use Evolve\Benchmarks\Comparator\ComparatorExecutionRunner;
use Evolve\Benchmarks\Comparator\ComparatorPreflight;
use Evolve\Benchmarks\Support\BenchmarkEnvironment;
use PHPUnit\Framework\TestCase;

final class ComparatorExecutionRunnerTest extends TestCase
{
    private string $outputDir;
    private string $workerDir;

    protected function setUp(): void
    {
        $this->outputDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evolvephp-comparator-runner-' . bin2hex(random_bytes(4));
        $this->workerDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'evolvephp-comparator-worker-' . bin2hex(random_bytes(4));
        mkdir($this->outputDir, 0777, true);
        mkdir($this->workerDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->outputDir);
        $this->removeDirectory($this->workerDir);
    }

    public function testRunnerUsesIsolatedSubprocessesAndWritesEvidenceManifest(): void
    {
        $runner = new ComparatorExecutionRunner(PHP_BINARY);
        $manifest = $runner->run(
            dirname(__DIR__, 2) . '/comparators/matrix.json',
            $this->outputDir,
            [
                'comparators' => ['evolvephp'],
                'scenarios' => ['http_static', 'http_not_found'],
                'samples' => 2,
                'warmups' => 1,
                'request_count' => 3,
                'preflight' => $this->matchedCurrentPreflight(),
            ],
        );

        self::assertSame('evolvephp.comparator.evidence-manifest.v1', $manifest['schema_version']);
        self::assertSame('completed', $manifest['status']);
        self::assertSame('subprocess_per_measured_sample', $manifest['process_isolation']['model']);
        self::assertSame(
            ['evolvephp:http_static', 'evolvephp:http_not_found'],
            $manifest['execution_order'],
        );
        self::assertCount(2, $manifest['results']);

        foreach ($manifest['results'] as $result) {
            self::assertSame('available', $result['availability']);
            self::assertSame(0, $result['exit_code']);
            self::assertStringNotContainsString('comparator-smoke.php', implode(' ', $result['command']));
            self::assertFileExists($this->outputDir . DIRECTORY_SEPARATOR . $result['raw_result']['path']);
            self::assertFileExists($this->outputDir . DIRECTORY_SEPARATOR . $result['normalized_result']['path']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['raw_result']['sha256']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['normalized_result']['sha256']);
            self::assertSame('prepared_warm_http_request', $result['timing_boundary']);
            self::assertSame(2, $result['sample_count']);
            self::assertCount(2, $result['samples_metadata']);
            self::assertCount(2, array_unique(array_column($result['samples_metadata'], 'pid')));
            self::assertSame($manifest['worker_environment_identity']['hash'], $result['worker_environment_identity']['hash']);
            self::assertSame($manifest['execution_environment_identity']['hash'], $result['execution_environment_identity']['hash']);
            self::assertNotSame($manifest['execution_environment_identity']['hash'], $result['worker_environment_identity']['hash']);
            self::assertSame('benchmarks/comparators/evolvephp', $result['dependency_context']['selected_comparator_root']);
            self::assertFalse($result['dependency_context']['benchmark_root_autoload_loaded']);
            self::assertSame('Evolve\Http\HttpKernel', $result['dependency_context']['selected_framework_class']);
            self::assertTrue($result['dependency_context']['selected_framework_loaded']);
            self::assertFalse($result['dependency_context']['unrelated_framework_classes_loaded']['laravel']);
            self::assertFalse($result['dependency_context']['unrelated_framework_classes_loaded']['symfony']);
            self::assertFalse($result['dependency_context']['unrelated_framework_classes_loaded']['slim']);

            $raw = json_decode((string) file_get_contents($this->outputDir . DIRECTORY_SEPARATOR . $result['raw_result']['path']), true, flags: JSON_THROW_ON_ERROR);
            self::assertCount(2, $raw['raw_samples']);

            foreach ($raw['raw_samples'] as $rawSample) {
                self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $rawSample['sha256']);
                self::assertSame($rawSample['sha256'], $this->hashJson($rawSample['record']));
                self::assertSame($rawSample['record']['sample_index'], $rawSample['sample_index']);
                self::assertArrayHasKey('worker_environment_identity', $rawSample['record']);
                self::assertArrayHasKey('dependency_context', $rawSample['record']);
                self::assertArrayHasKey('subject_metadata', $rawSample['record']);
                self::assertArrayHasKey('last_result', $rawSample['record']);
                self::assertArrayHasKey('process', $rawSample['record']);
            }

            self::assertSame(
                array_column($result['samples_metadata'], 'raw_sample_sha256'),
                array_column($raw['raw_samples'], 'sha256'),
            );

            $normalized = json_decode((string) file_get_contents($this->outputDir . DIRECTORY_SEPARATOR . $result['normalized_result']['path']), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($manifest['execution_environment_identity']['hash'], $normalized['execution_environment_identity']['hash']);
            self::assertSame($manifest['execution_environment_identity']['hash'], $normalized['execution_environment_fingerprint']);
        }
    }

    public function testExternalComparatorDependencyContextIsCapturedAfterSelectedFrameworkLoads(): void
    {
        if (!is_file(dirname(__DIR__, 2) . '/comparators/laravel/vendor/autoload.php')) {
            self::markTestSkipped('Laravel comparator dependency root is not installed.');
        }

        $runner = new ComparatorExecutionRunner(PHP_BINARY);
        $manifest = $runner->run(
            dirname(__DIR__, 2) . '/comparators/matrix.json',
            $this->outputDir,
            [
                'comparators' => ['laravel'],
                'scenarios' => ['http_static'],
                'samples' => 1,
                'warmups' => 0,
                'request_count' => 1,
                'preflight' => $this->matchedCurrentPreflight(),
            ],
        );

        $context = $manifest['results'][0]['dependency_context'];

        self::assertSame('benchmarks/comparators/laravel', $context['selected_comparator_root']);
        self::assertSame('Illuminate\Routing\Router', $context['selected_framework_class']);
        self::assertTrue($context['selected_framework_loaded']);
        self::assertFalse($context['benchmark_root_autoload_loaded']);
        self::assertFalse($context['unrelated_framework_classes_loaded']['evolvephp']);
        self::assertFalse($context['unrelated_framework_classes_loaded']['symfony']);
        self::assertFalse($context['unrelated_framework_classes_loaded']['slim']);
    }

    public function testMismatchedPreflightBlocksTimingAndCreatesNoRawOrNormalizedArtifacts(): void
    {
        $runner = new ComparatorExecutionRunner(PHP_BINARY);
        $manifest = $runner->run(
            dirname(__DIR__, 2) . '/comparators/matrix.json',
            $this->outputDir,
            [
                'comparators' => ['evolvephp'],
                'scenarios' => ['http_static'],
                'samples' => 2,
                'preflight' => [
                    'schema_version' => 'evolvephp.comparator.preflight.v1',
                    'status' => 'mismatched',
                    'mismatches' => ['PHP version is not exactly 8.4.25.'],
                    'environment' => BenchmarkEnvironment::capture(dirname(__DIR__, 3)),
                    'environment_identity' => null,
                ],
            ],
        );

        self::assertSame('preflight_rejected', $manifest['status']);
        self::assertSame(['PHP version is not exactly 8.4.25.'], $manifest['preflight']['mismatches']);
        self::assertSame([], $manifest['results']);
        self::assertFileExists($this->outputDir . DIRECTORY_SEPARATOR . 'preflight.json');
        self::assertFileExists($this->outputDir . DIRECTORY_SEPARATOR . 'manifest.json');
        self::assertDirectoryDoesNotExist($this->outputDir . DIRECTORY_SEPARATOR . 'raw');
        self::assertDirectoryDoesNotExist($this->outputDir . DIRECTORY_SEPARATOR . 'normalized');
        self::assertSame(['manifest.json', 'preflight.json'], $this->outputDirectoryFiles());
    }

    public function testUnavailableComparatorDoesNotInventTimingSamples(): void
    {
        $runner = new ComparatorExecutionRunner(PHP_BINARY);
        $manifest = $runner->run(
            dirname(__DIR__, 2) . '/comparators/matrix.json',
            $this->outputDir,
            [
                'comparators' => ['phalcon'],
                'scenarios' => ['http_static'],
                'samples' => 2,
                'preflight' => $this->matchedCurrentPreflight(),
            ],
        );

        if ($manifest['results'][0]['availability'] === 'available') {
            self::markTestSkipped('ext-phalcon is available in this environment; unavailable path cannot be asserted.');
        }

        self::assertSame('unavailable', $manifest['results'][0]['availability']);
        self::assertSame(0, $manifest['results'][0]['sample_count']);
        self::assertArrayHasKey('reason', $manifest['results'][0]);

        $normalizedPath = $this->outputDir . DIRECTORY_SEPARATOR . $manifest['results'][0]['normalized_result']['path'];
        $normalized = json_decode((string) file_get_contents($normalizedPath), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('unavailable', $normalized['availability']);
        self::assertNull($normalized['baseline_result']);
        self::assertArrayNotHasKey('timing', $normalized);
    }

    public function testBrokenAvailableComparatorFailureRemainsFailed(): void
    {
        $brokenWorker = $this->workerDir . DIRECTORY_SEPARATOR . 'broken-worker.php';
        file_put_contents($brokenWorker, "<?php fwrite(STDERR, 'fixture exploded' . PHP_EOL); exit(1);\n");

        $runner = new ComparatorExecutionRunner(PHP_BINARY);
        $manifest = $runner->run(
            dirname(__DIR__, 2) . '/comparators/matrix.json',
            $this->outputDir,
            [
                'comparators' => ['evolvephp'],
                'scenarios' => ['http_static'],
                'samples' => 1,
                'preflight' => $this->matchedCurrentPreflight(),
                'worker_path' => $brokenWorker,
            ],
        );

        self::assertSame('failed', $manifest['status']);
        self::assertSame('failed', $manifest['results'][0]['availability']);
        self::assertSame(0, $manifest['results'][0]['sample_count']);
        self::assertSame(1, $manifest['results'][0]['exit_code']);

        $normalizedPath = $this->outputDir . DIRECTORY_SEPARATOR . $manifest['results'][0]['normalized_result']['path'];
        $normalized = json_decode((string) file_get_contents($normalizedPath), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('failed', $normalized['availability']);
        self::assertNull($normalized['baseline_result']);
        self::assertArrayNotHasKey('timing', $normalized);
    }

    public function testWrongWorkerRuntimeIdentityRejectsValidLookingAvailableSample(): void
    {
        $mismatchedWorker = $this->workerDir . DIRECTORY_SEPARATOR . 'mismatched-worker.php';
        file_put_contents($mismatchedWorker, <<<'PHP'
<?php
echo json_encode([
    'schema_version' => 'evolvephp.comparator.raw-result.v1',
    'comparator_id' => 'evolvephp',
    'scenario_id' => 'http_static',
    'availability' => 'available',
    'availability_status' => 'available',
    'timing_boundary' => 'prepared_warm_http_request',
    'sample_count' => 1,
    'sample_index' => 1,
    'samples' => [123.0],
    'unit' => 'microseconds',
    'memory' => ['peak_bytes' => 1],
    'warmups' => 0,
    'request_count' => 1,
    'operations_per_sample' => 1,
    'worker_environment_identity' => [
        'schema_version' => 'evolvephp.comparator.runtime-identity.v1',
        'hash' => '0000000000000000000000000000000000000000000000000000000000000000',
    ],
    'dependency_context' => [],
    'process' => ['pid' => getmypid()],
    'subject_metadata' => [],
    'last_result' => ['status_code' => 200],
], JSON_THROW_ON_ERROR);
PHP);

        $runner = new ComparatorExecutionRunner(PHP_BINARY);
        $manifest = $runner->run(
            dirname(__DIR__, 2) . '/comparators/matrix.json',
            $this->outputDir,
            [
                'comparators' => ['evolvephp'],
                'scenarios' => ['http_static'],
                'samples' => 1,
                'warmups' => 0,
                'request_count' => 1,
                'preflight' => $this->matchedCurrentPreflight(),
                'worker_path' => $mismatchedWorker,
            ],
        );

        self::assertSame('failed', $manifest['status']);
        self::assertSame('failed', $manifest['results'][0]['availability']);
        self::assertSame('measurement worker runtime identity did not match accepted preflight lane', $manifest['results'][0]['reason']);

        $normalized = json_decode((string) file_get_contents($this->outputDir . DIRECTORY_SEPARATOR . $manifest['results'][0]['normalized_result']['path']), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('failed', $normalized['availability']);
        self::assertSame('measurement worker runtime identity did not match accepted preflight lane', $normalized['failure_reason']);
        self::assertNull($normalized['baseline_result']);
    }

    public function testWrongWorkerRuntimeIdentityRejectsUnavailableSampleFromMatchedPreflight(): void
    {
        $mismatchedUnavailableWorker = $this->workerDir . DIRECTORY_SEPARATOR . 'mismatched-unavailable-worker.php';
        file_put_contents($mismatchedUnavailableWorker, <<<'PHP'
<?php
echo json_encode([
    'schema_version' => 'evolvephp.comparator.raw-result.v1',
    'comparator_id' => 'phalcon',
    'scenario_id' => 'http_static',
    'availability' => 'unavailable',
    'availability_status' => 'unavailable',
    'reason' => 'Phalcon extension is not loaded in worker',
    'sample_count' => 0,
    'samples' => [],
    'unit' => 'microseconds',
    'worker_environment_identity' => [
        'schema_version' => 'evolvephp.comparator.runtime-identity.v1',
        'hash' => 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff',
    ],
    'dependency_context' => [],
    'process' => ['pid' => getmypid()],
], JSON_THROW_ON_ERROR);
PHP);

        $runner = new ComparatorExecutionRunner(PHP_BINARY);
        $manifest = $runner->run(
            dirname(__DIR__, 2) . '/comparators/matrix.json',
            $this->outputDir,
            [
                'comparators' => ['phalcon'],
                'scenarios' => ['http_static'],
                'samples' => 1,
                'warmups' => 0,
                'request_count' => 1,
                'preflight' => $this->matchedCurrentPreflight(),
                'worker_path' => $mismatchedUnavailableWorker,
            ],
        );

        self::assertSame('failed', $manifest['status']);
        self::assertSame('failed', $manifest['results'][0]['availability']);
        self::assertSame('measurement worker runtime identity did not match accepted preflight lane', $manifest['results'][0]['reason']);
        self::assertSame(1, $manifest['results'][0]['exit_code']);

        $normalized = json_decode((string) file_get_contents($this->outputDir . DIRECTORY_SEPARATOR . $manifest['results'][0]['normalized_result']['path']), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('failed', $normalized['availability']);
        self::assertSame('measurement worker runtime identity did not match accepted preflight lane', $normalized['failure_reason']);
        self::assertNull($normalized['baseline_result']);
    }

    public function testApplicationBootRunsOneDiscardedWorkerBeforeEveryMeasuredSampleAndExcludesDiscardedTiming(): void
    {
        $preflight = $this->matchedCurrentPreflight();
        $worker = $this->writeCountingWorker((string) $preflight['worker_environment_identity']['hash']);

        $runner = new ComparatorExecutionRunner(PHP_BINARY);
        $manifest = $runner->run(
            dirname(__DIR__, 2) . '/comparators/matrix.json',
            $this->outputDir,
            [
                'comparators' => ['evolvephp'],
                'scenarios' => ['application_boot'],
                'samples' => 2,
                'warmups' => 5,
                'request_count' => 1,
                'preflight' => $preflight,
                'worker_path' => $worker,
            ],
        );

        self::assertSame([
            'discarded_worker_processes_per_measured_sample' => 1,
            'sample_order' => 'rotating_round_robin',
            'measured_worker_in_process_warmups' => 0,
            'outlier_policy' => 'retain_all_measured_samples',
            'primary_central_statistic' => 'p50',
        ], $manifest['boot_protocol']);
        self::assertSame('completed', $manifest['status']);
        self::assertCount(4, $manifest['boot_worker_execution_sequence']);
        self::assertSame(['discarded', 'measured', 'discarded', 'measured'], array_column($manifest['boot_worker_execution_sequence'], 'role'));
        self::assertSame([true, false, true, false], array_column($manifest['boot_worker_execution_sequence'], 'excluded_from_statistics'));
        self::assertSame([1, 1, 2, 2], array_column($manifest['boot_worker_execution_sequence'], 'sample_index'));
        self::assertSame(['available', 'available', 'available', 'available'], array_column($manifest['boot_worker_execution_sequence'], 'availability'));
        self::assertSame(
            array_column($manifest['boot_worker_execution_sequence'], 'worker_environment_identity_hash'),
            array_fill(0, 4, $preflight['worker_environment_identity']['hash']),
        );

        foreach (array_chunk($manifest['boot_worker_execution_sequence'], 2) as $pair) {
            self::assertSame('discarded', $pair[0]['role']);
            self::assertSame('measured', $pair[1]['role']);
            self::assertTrue($pair[0]['excluded_from_statistics']);
            self::assertFalse($pair[1]['excluded_from_statistics']);
            self::assertSame($pair[0]['comparator_id'], $pair[1]['comparator_id']);
            self::assertSame($pair[0]['sample_index'], $pair[1]['sample_index']);
            self::assertNotSame($pair[0]['pid'], $pair[1]['pid']);
        }

        $result = $manifest['results'][0];
        self::assertSame('application_boot', $result['scenario_id']);
        self::assertSame(2, $result['sample_count']);
        self::assertCount(2, $result['commands']);
        self::assertCount(2, $result['raw_samples']);
        self::assertCount(2, $result['discarded_worker_provenance']);

        $raw = json_decode((string) file_get_contents($this->outputDir . DIRECTORY_SEPARATOR . $result['raw_result']['path']), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $raw['warmups']);
        self::assertSame(2, $raw['sample_count']);
        self::assertSame([2.0, 4.0], array_map('floatval', $raw['samples']));
        self::assertCount(2, $raw['raw_samples']);
        self::assertCount(2, $raw['discarded_worker_provenance']);
        self::assertSame([1.0, 3.0], array_map('floatval', array_column($raw['discarded_worker_provenance'], 'duration_microseconds')));
        self::assertSame([true, true], array_column($raw['discarded_worker_provenance'], 'excluded_from_statistics'));

        $normalized = json_decode((string) file_get_contents($this->outputDir . DIRECTORY_SEPARATOR . $result['normalized_result']['path']), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $normalized['baseline_result']['scenarios'][0]['sample_count']);
        self::assertSame(3.0, (float) $normalized['baseline_result']['scenarios'][0]['p50']);
    }

    public function testApplicationBootUsesDeterministicRotatingRoundRobinAcrossComparators(): void
    {
        $preflight = $this->matchedCurrentPreflight();
        $worker = $this->writeCountingWorker((string) $preflight['worker_environment_identity']['hash']);

        $runner = new ComparatorExecutionRunner(PHP_BINARY);
        $manifest = $runner->run(
            dirname(__DIR__, 2) . '/comparators/matrix.json',
            $this->outputDir,
            [
                'comparators' => ['evolvephp', 'slim', 'symfony'],
                'scenarios' => ['application_boot'],
                'samples' => 3,
                'warmups' => 0,
                'request_count' => 1,
                'preflight' => $preflight,
                'worker_path' => $worker,
            ],
        );

        $slots = [];

        foreach (array_chunk($manifest['boot_worker_execution_sequence'], 2) as $pair) {
            self::assertSame('discarded', $pair[0]['role']);
            self::assertSame('measured', $pair[1]['role']);
            self::assertTrue($pair[0]['excluded_from_statistics']);
            self::assertFalse($pair[1]['excluded_from_statistics']);
            self::assertSame($pair[0]['comparator_id'], $pair[1]['comparator_id']);
            self::assertSame($pair[0]['sample_index'], $pair[1]['sample_index']);
            $slots[] = $pair[1]['comparator_id'] . ':' . $pair[1]['sample_index'];
        }

        self::assertSame([
            'evolvephp:1',
            'slim:1',
            'symfony:1',
            'slim:2',
            'symfony:2',
            'evolvephp:2',
            'symfony:3',
            'evolvephp:3',
            'slim:3',
        ], $slots);
        self::assertSame([
            'evolvephp:application_boot',
            'slim:application_boot',
            'symfony:application_boot',
        ], $manifest['execution_order']);
    }

    public function testDiscardedApplicationBootWorkerRuntimeMismatchFailsClosed(): void
    {
        $preflight = $this->matchedCurrentPreflight();
        $worker = $this->writeCountingWorker(
            (string) $preflight['worker_environment_identity']['hash'],
            '1111111111111111111111111111111111111111111111111111111111111111',
            [1],
        );

        $runner = new ComparatorExecutionRunner(PHP_BINARY);
        $manifest = $runner->run(
            dirname(__DIR__, 2) . '/comparators/matrix.json',
            $this->outputDir,
            [
                'comparators' => ['evolvephp'],
                'scenarios' => ['application_boot'],
                'samples' => 1,
                'warmups' => 0,
                'request_count' => 1,
                'preflight' => $preflight,
                'worker_path' => $worker,
            ],
        );

        self::assertSame('failed', $manifest['status']);
        self::assertSame('failed', $manifest['results'][0]['availability']);
        self::assertSame('discarded boot worker runtime identity did not match accepted preflight lane', $manifest['results'][0]['reason']);
        self::assertSame(1, $manifest['results'][0]['exit_code']);
        self::assertSame(0, $manifest['results'][0]['sample_count']);
        self::assertSame([], $manifest['results'][0]['raw_samples']);
        self::assertCount(1, $manifest['boot_worker_execution_sequence']);
        self::assertSame('discarded', $manifest['boot_worker_execution_sequence'][0]['role']);
        self::assertSame(0, $manifest['boot_worker_execution_sequence'][0]['exit_code']);
        self::assertTrue($manifest['boot_worker_execution_sequence'][0]['excluded_from_statistics']);

        $normalized = json_decode((string) file_get_contents($this->outputDir . DIRECTORY_SEPARATOR . $manifest['results'][0]['normalized_result']['path']), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('failed', $normalized['availability']);
        self::assertNull($normalized['baseline_result']);
    }

    public function testMeasuredApplicationBootWorkerRuntimeMismatchFailsClosedWithNonZeroAggregateExitCode(): void
    {
        $preflight = $this->matchedCurrentPreflight();
        $worker = $this->writeCountingWorker(
            (string) $preflight['worker_environment_identity']['hash'],
            '2222222222222222222222222222222222222222222222222222222222222222',
            [2],
        );

        $runner = new ComparatorExecutionRunner(PHP_BINARY);
        $manifest = $runner->run(
            dirname(__DIR__, 2) . '/comparators/matrix.json',
            $this->outputDir,
            [
                'comparators' => ['evolvephp'],
                'scenarios' => ['application_boot'],
                'samples' => 1,
                'warmups' => 0,
                'request_count' => 1,
                'preflight' => $preflight,
                'worker_path' => $worker,
            ],
        );

        self::assertSame('failed', $manifest['status']);
        self::assertSame('failed', $manifest['results'][0]['availability']);
        self::assertSame('measurement worker runtime identity did not match accepted preflight lane', $manifest['results'][0]['reason']);
        self::assertSame(1, $manifest['results'][0]['exit_code']);
        self::assertSame(0, $manifest['results'][0]['sample_count']);
        self::assertSame([], $manifest['results'][0]['raw_samples']);
        self::assertCount(2, $manifest['boot_worker_execution_sequence']);
        self::assertSame(['discarded', 'measured'], array_column($manifest['boot_worker_execution_sequence'], 'role'));
        self::assertSame([true, false], array_column($manifest['boot_worker_execution_sequence'], 'excluded_from_statistics'));
        self::assertSame([0, 0], array_column($manifest['boot_worker_execution_sequence'], 'exit_code'));

        $normalized = json_decode((string) file_get_contents($this->outputDir . DIRECTORY_SEPARATOR . $manifest['results'][0]['normalized_result']['path']), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('failed', $normalized['availability']);
        self::assertSame('measurement worker runtime identity did not match accepted preflight lane', $normalized['failure_reason']);
        self::assertNull($normalized['baseline_result']);
    }

    public function testDiscardedApplicationBootWorkerProcessFailureStopsBeforeMeasuredWorkerAndNormalizesNoTiming(): void
    {
        $preflight = $this->matchedCurrentPreflight();
        $worker = $this->writeCountingWorker(
            (string) $preflight['worker_environment_identity']['hash'],
            failedInvocations: [1],
        );

        $runner = new ComparatorExecutionRunner(PHP_BINARY);
        $manifest = $runner->run(
            dirname(__DIR__, 2) . '/comparators/matrix.json',
            $this->outputDir,
            [
                'comparators' => ['evolvephp'],
                'scenarios' => ['application_boot'],
                'samples' => 1,
                'warmups' => 0,
                'request_count' => 1,
                'preflight' => $preflight,
                'worker_path' => $worker,
            ],
        );

        self::assertSame('failed', $manifest['status']);
        self::assertSame('failed', $manifest['results'][0]['availability']);
        self::assertNotSame(0, $manifest['results'][0]['exit_code']);
        self::assertSame(0, $manifest['results'][0]['sample_count']);
        self::assertSame([], $manifest['results'][0]['raw_samples']);
        self::assertCount(1, $manifest['boot_worker_execution_sequence']);
        self::assertSame('discarded', $manifest['boot_worker_execution_sequence'][0]['role']);
        self::assertSame(7, $manifest['boot_worker_execution_sequence'][0]['exit_code']);
        self::assertTrue($manifest['boot_worker_execution_sequence'][0]['excluded_from_statistics']);

        $raw = json_decode((string) file_get_contents($this->outputDir . DIRECTORY_SEPARATOR . $manifest['results'][0]['raw_result']['path']), true, flags: JSON_THROW_ON_ERROR);
        $normalized = json_decode((string) file_get_contents($this->outputDir . DIRECTORY_SEPARATOR . $manifest['results'][0]['normalized_result']['path']), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([], $raw['samples']);
        self::assertSame([], $raw['raw_samples']);
        self::assertSame('failed', $normalized['availability']);
        self::assertNull($normalized['baseline_result']);
    }

    public function testLaterDiscardedApplicationBootWorkerProcessFailurePreservesEarlierMeasuredEvidence(): void
    {
        $preflight = $this->matchedCurrentPreflight();
        $worker = $this->writeCountingWorker(
            (string) $preflight['worker_environment_identity']['hash'],
            failedInvocations: [3],
        );

        $runner = new ComparatorExecutionRunner(PHP_BINARY);
        $manifest = $runner->run(
            dirname(__DIR__, 2) . '/comparators/matrix.json',
            $this->outputDir,
            [
                'comparators' => ['evolvephp'],
                'scenarios' => ['application_boot'],
                'samples' => 2,
                'warmups' => 0,
                'request_count' => 1,
                'preflight' => $preflight,
                'worker_path' => $worker,
            ],
        );

        self::assertSame('failed', $manifest['status']);
        self::assertSame('failed', $manifest['results'][0]['availability']);
        self::assertNotSame(0, $manifest['results'][0]['exit_code']);
        self::assertSame(1, $manifest['results'][0]['sample_count']);
        self::assertCount(1, $manifest['results'][0]['raw_samples']);
        self::assertCount(1, $manifest['results'][0]['samples_metadata']);
        self::assertCount(1, $manifest['results'][0]['commands']);
        self::assertCount(3, $manifest['boot_worker_execution_sequence']);
        self::assertSame(['discarded', 'measured', 'discarded'], array_column($manifest['boot_worker_execution_sequence'], 'role'));
        self::assertSame(7, $manifest['boot_worker_execution_sequence'][2]['exit_code']);
        self::assertTrue($manifest['boot_worker_execution_sequence'][2]['excluded_from_statistics']);

        $raw = json_decode((string) file_get_contents($this->outputDir . DIRECTORY_SEPARATOR . $manifest['results'][0]['raw_result']['path']), true, flags: JSON_THROW_ON_ERROR);
        $normalized = json_decode((string) file_get_contents($this->outputDir . DIRECTORY_SEPARATOR . $manifest['results'][0]['normalized_result']['path']), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([2.0], array_map('floatval', $raw['samples']));
        self::assertSame(1, $raw['sample_count']);
        self::assertCount(1, $raw['raw_samples']);
        self::assertArrayHasKey('sha256', $raw['raw_samples'][0]);
        self::assertSame(2.0, (float) $raw['raw_samples'][0]['record']['samples'][0]);
        self::assertCount(1, $raw['samples_metadata']);
        self::assertCount(1, $raw['commands']);
        self::assertSame('ok', $raw['last_result']['status']);
        self::assertSame(2, $raw['memory']['peak_bytes']);
        self::assertSame('failed', $normalized['availability']);
        self::assertNull($normalized['baseline_result']);
    }

    public function testLaterDiscardedApplicationBootWorkerUnavailableAfterAcceptedSampleFailsAndPreservesEvidence(): void
    {
        $preflight = $this->matchedCurrentPreflight();
        $worker = $this->writeCountingWorker(
            (string) $preflight['worker_environment_identity']['hash'],
            unavailableInvocations: [3],
        );

        $runner = new ComparatorExecutionRunner(PHP_BINARY);
        $manifest = $runner->run(
            dirname(__DIR__, 2) . '/comparators/matrix.json',
            $this->outputDir,
            [
                'comparators' => ['evolvephp'],
                'scenarios' => ['application_boot'],
                'samples' => 2,
                'warmups' => 0,
                'request_count' => 1,
                'preflight' => $preflight,
                'worker_path' => $worker,
            ],
        );

        self::assertSame('failed', $manifest['status']);
        self::assertSame('failed', $manifest['results'][0]['availability']);
        self::assertSame(1, $manifest['results'][0]['exit_code']);
        self::assertSame(1, $manifest['results'][0]['sample_count']);
        self::assertStringContainsString('availability changed during controlled boot sampling', (string) $manifest['results'][0]['reason']);
        self::assertCount(1, $manifest['results'][0]['raw_samples']);
        self::assertCount(3, $manifest['boot_worker_execution_sequence']);
        self::assertSame(['available', 'available', 'unavailable'], array_column($manifest['boot_worker_execution_sequence'], 'availability'));

        $raw = json_decode((string) file_get_contents($this->outputDir . DIRECTORY_SEPARATOR . $manifest['results'][0]['raw_result']['path']), true, flags: JSON_THROW_ON_ERROR);
        $normalized = json_decode((string) file_get_contents($this->outputDir . DIRECTORY_SEPARATOR . $manifest['results'][0]['normalized_result']['path']), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([2.0], array_map('floatval', $raw['samples']));
        self::assertCount(1, $raw['raw_samples']);
        self::assertCount(1, $raw['samples_metadata']);
        self::assertCount(1, $raw['commands']);
        self::assertSame('failed', $normalized['availability']);
        self::assertNull($normalized['baseline_result']);
    }

    public function testMeasuredApplicationBootWorkerUnavailableAfterAvailableDiscardFailsWithoutFabricatedTiming(): void
    {
        $preflight = $this->matchedCurrentPreflight();
        $worker = $this->writeCountingWorker(
            (string) $preflight['worker_environment_identity']['hash'],
            unavailableInvocations: [2],
        );

        $runner = new ComparatorExecutionRunner(PHP_BINARY);
        $manifest = $runner->run(
            dirname(__DIR__, 2) . '/comparators/matrix.json',
            $this->outputDir,
            [
                'comparators' => ['evolvephp'],
                'scenarios' => ['application_boot'],
                'samples' => 1,
                'warmups' => 0,
                'request_count' => 1,
                'preflight' => $preflight,
                'worker_path' => $worker,
            ],
        );

        self::assertSame('failed', $manifest['status']);
        self::assertSame('failed', $manifest['results'][0]['availability']);
        self::assertSame(1, $manifest['results'][0]['exit_code']);
        self::assertSame(0, $manifest['results'][0]['sample_count']);
        self::assertSame([], $manifest['results'][0]['raw_samples']);
        self::assertStringContainsString('inconsistent availability within measured boot slot', (string) $manifest['results'][0]['reason']);
        self::assertCount(2, $manifest['boot_worker_execution_sequence']);
        self::assertSame(['discarded', 'measured'], array_column($manifest['boot_worker_execution_sequence'], 'role'));
        self::assertSame(['available', 'unavailable'], array_column($manifest['boot_worker_execution_sequence'], 'availability'));
        self::assertSame([true, false], array_column($manifest['boot_worker_execution_sequence'], 'excluded_from_statistics'));

        $raw = json_decode((string) file_get_contents($this->outputDir . DIRECTORY_SEPARATOR . $manifest['results'][0]['raw_result']['path']), true, flags: JSON_THROW_ON_ERROR);
        $normalized = json_decode((string) file_get_contents($this->outputDir . DIRECTORY_SEPARATOR . $manifest['results'][0]['normalized_result']['path']), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([], $raw['samples']);
        self::assertSame([], $raw['raw_samples']);
        self::assertSame([], $raw['samples_metadata']);
        self::assertSame('failed', $normalized['availability']);
        self::assertNull($normalized['baseline_result']);
    }

    public function testDiscardedApplicationBootWorkerUnavailableStopsBeforeMeasuredWorkerAndFabricatesNoTiming(): void
    {
        $preflight = $this->matchedCurrentPreflight();
        $worker = $this->writeCountingWorker(
            (string) $preflight['worker_environment_identity']['hash'],
            unavailableInvocations: [1],
        );

        $runner = new ComparatorExecutionRunner(PHP_BINARY);
        $manifest = $runner->run(
            dirname(__DIR__, 2) . '/comparators/matrix.json',
            $this->outputDir,
            [
                'comparators' => ['evolvephp'],
                'scenarios' => ['application_boot'],
                'samples' => 1,
                'warmups' => 0,
                'request_count' => 1,
                'preflight' => $preflight,
                'worker_path' => $worker,
            ],
        );

        self::assertSame('completed', $manifest['status']);
        self::assertSame('unavailable', $manifest['results'][0]['availability']);
        self::assertSame(0, $manifest['results'][0]['exit_code']);
        self::assertSame(0, $manifest['results'][0]['sample_count']);
        self::assertSame([], $manifest['results'][0]['raw_samples']);
        self::assertCount(1, $manifest['boot_worker_execution_sequence']);
        self::assertSame('discarded', $manifest['boot_worker_execution_sequence'][0]['role']);
        self::assertSame(0, $manifest['boot_worker_execution_sequence'][0]['exit_code']);
        self::assertSame('unavailable', $manifest['boot_worker_execution_sequence'][0]['availability']);
        self::assertTrue($manifest['boot_worker_execution_sequence'][0]['excluded_from_statistics']);

        $raw = json_decode((string) file_get_contents($this->outputDir . DIRECTORY_SEPARATOR . $manifest['results'][0]['raw_result']['path']), true, flags: JSON_THROW_ON_ERROR);
        $normalized = json_decode((string) file_get_contents($this->outputDir . DIRECTORY_SEPARATOR . $manifest['results'][0]['normalized_result']['path']), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([], $raw['raw_samples']);
        self::assertSame('unavailable', $normalized['availability']);
        self::assertNull($normalized['baseline_result']);
    }

    public function testWarmHttpScenariosDoNotReceiveDiscardedWorkers(): void
    {
        $preflight = $this->matchedCurrentPreflight();
        $worker = $this->writeCountingWorker((string) $preflight['worker_environment_identity']['hash']);

        $runner = new ComparatorExecutionRunner(PHP_BINARY);
        $manifest = $runner->run(
            dirname(__DIR__, 2) . '/comparators/matrix.json',
            $this->outputDir,
            [
                'comparators' => ['evolvephp'],
                'scenarios' => ['http_static'],
                'samples' => 2,
                'warmups' => 5,
                'request_count' => 1,
                'preflight' => $preflight,
                'worker_path' => $worker,
            ],
        );

        self::assertSame([], $manifest['boot_worker_execution_sequence']);
        self::assertSame('completed', $manifest['status']);
        self::assertSame(2, $manifest['results'][0]['sample_count']);
        self::assertCount(2, $manifest['results'][0]['commands']);
        self::assertArrayNotHasKey('discarded_worker_provenance', $manifest['results'][0]);

        $raw = json_decode((string) file_get_contents($this->outputDir . DIRECTORY_SEPARATOR . $manifest['results'][0]['raw_result']['path']), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(5, $raw['warmups']);
        self::assertCount(2, $raw['raw_samples']);
        self::assertArrayNotHasKey('discarded_worker_provenance', $raw);
    }

    public function testPrePopulatedOutputDirectoryIsRejectedBeforeNewEvidenceIsWritten(): void
    {
        file_put_contents($this->outputDir . DIRECTORY_SEPARATOR . 'stale-evidence.json', '{}');

        $runner = new ComparatorExecutionRunner(PHP_BINARY);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Comparator output directory must be absent or empty before a run begins.');

        try {
            $runner->run(
                dirname(__DIR__, 2) . '/comparators/matrix.json',
                $this->outputDir,
                [
                    'comparators' => ['evolvephp'],
                    'scenarios' => ['http_static'],
                    'samples' => 1,
                    'warmups' => 0,
                    'request_count' => 1,
                    'preflight' => $this->matchedCurrentPreflight(),
                ],
            );
        } finally {
            self::assertSame(['stale-evidence.json'], $this->outputDirectoryFiles());
            self::assertFileDoesNotExist($this->outputDir . DIRECTORY_SEPARATOR . 'preflight.json');
            self::assertFileDoesNotExist($this->outputDir . DIRECTORY_SEPARATOR . 'manifest.json');
            self::assertDirectoryDoesNotExist($this->outputDir . DIRECTORY_SEPARATOR . 'raw');
            self::assertDirectoryDoesNotExist($this->outputDir . DIRECTORY_SEPARATOR . 'normalized');
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
                continue;
            }

            unlink($file->getPathname());
        }

        rmdir($path);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hashJson(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    /**
     * @return list<string>
     */
    private function outputDirectoryFiles(): array
    {
        $files = array_values(array_diff(scandir($this->outputDir) ?: [], ['.', '..']));
        sort($files);

        return $files;
    }

    /**
     * @param list<int> $mismatchedInvocations
     * @param list<int> $failedInvocations
     * @param list<int> $unavailableInvocations
     */
    private function writeCountingWorker(
        string $identityHash,
        ?string $mismatchedIdentityHash = null,
        array $mismatchedInvocations = [],
        array $failedInvocations = [],
        array $unavailableInvocations = [],
    ): string {
        $worker = $this->workerDir . DIRECTORY_SEPARATOR . 'counting-worker-' . bin2hex(random_bytes(4)) . '.php';
        $state = $this->workerDir . DIRECTORY_SEPARATOR . 'counting-worker-state-' . bin2hex(random_bytes(4)) . '.txt';
        $mismatchedIdentityHash ??= $identityHash;
        $mismatchedInvocationsExport = var_export($mismatchedInvocations, true);
        $failedInvocationsExport = var_export($failedInvocations, true);
        $unavailableInvocationsExport = var_export($unavailableInvocations, true);

        file_put_contents($worker, <<<PHP
<?php
\$options = getopt('', ['matrix::', 'comparator::', 'scenario::', 'warmups::', 'request-count::', 'sample-index::']);
\$state = '$state';
\$invocation = is_file(\$state) ? (int) file_get_contents(\$state) : 0;
++ \$invocation;
file_put_contents(\$state, (string) \$invocation);
\$failedInvocations = $failedInvocationsExport;
if (in_array(\$invocation, \$failedInvocations, true)) {
    fwrite(STDERR, 'deterministic boot worker process failure');
    exit(7);
}
\$mismatchedInvocations = $mismatchedInvocationsExport;
\$unavailableInvocations = $unavailableInvocationsExport;
\$availability = in_array(\$invocation, \$unavailableInvocations, true) ? 'unavailable' : 'available';
\$samples = \$availability === 'available' ? [(float) \$invocation] : [];
\$identityHash = in_array(\$invocation, \$mismatchedInvocations, true)
    ? '$mismatchedIdentityHash'
    : '$identityHash';
\$scenarioId = (string) (\$options['scenario'] ?? 'application_boot');
\$timingBoundary = \$scenarioId === 'application_boot' ? 'application_boot_constructs_framework' : 'prepared_warm_http_request';
\$preparedCount = \$scenarioId === 'application_boot' ? 0 : 1;
echo json_encode([
    'schema_version' => 'evolvephp.comparator.raw-result.v1',
    'comparator_id' => (string) (\$options['comparator'] ?? 'evolvephp'),
    'scenario_id' => \$scenarioId,
    'availability' => \$availability,
    'availability_status' => \$availability,
    'reason' => \$availability === 'unavailable' ? 'deterministic comparator unavailable' : null,
    'timing_boundary' => \$timingBoundary,
    'prepared_framework_instance_count' => \$preparedCount,
    'sample_count' => count(\$samples),
    'sample_index' => (int) (\$options['sample-index'] ?? 1),
    'samples' => \$samples,
    'unit' => 'microseconds',
    'memory' => ['current_bytes' => \$invocation, 'peak_bytes' => \$invocation],
    'warmups' => (int) (\$options['warmups'] ?? 0),
    'request_count' => (int) (\$options['request-count'] ?? 1),
    'operations_per_sample' => 1,
    'worker_environment_identity' => [
        'schema_version' => 'evolvephp.comparator.runtime-identity.v1',
        'hash' => \$identityHash,
    ],
    'dependency_context' => [],
    'process' => ['pid' => getmypid()],
    'subject_metadata' => [
        'scenario_id' => \$scenarioId,
        'timing_boundary' => \$timingBoundary,
        'prepared_framework_instance_count' => \$preparedCount,
    ],
    'last_result' => [
        'status' => 'ok',
        'framework_constructed_in_subject' => \$scenarioId === 'application_boot',
        'framework_prepared_outside_subject' => \$scenarioId !== 'application_boot',
        'normal_framework_path_executed' => \$scenarioId !== 'application_boot',
    ],
], JSON_THROW_ON_ERROR);
PHP);

        return $worker;
    }

    /**
     * @return array<string, mixed>
     */
    private function matchedCurrentPreflight(): array
    {
        return ComparatorPreflight::fromEnvironment(
            BenchmarkEnvironment::capture(dirname(__DIR__, 3)),
            dirname(__DIR__, 2) . '/comparators/matrix.json',
            [
                'php_version' => PHP_VERSION,
                'opcache_cli_enabled' => filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOLEAN),
                'jit_enabled' => false,
                'phalcon_extension_version' => phpversion('phalcon') ?: null,
            ],
        );
    }
}
