<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Comparator;

use DateTimeImmutable;
use DateTimeZone;
use Evolve\Benchmarks\Support\ExecutionEnvironmentFingerprint;
use Evolve\Benchmarks\Support\FixtureIdentity;
use RuntimeException;

final class ComparatorExecutionRunner
{
    public const MANIFEST_SCHEMA_VERSION = 'evolvephp.comparator.evidence-manifest.v1';

    public function __construct(private readonly string $phpBinary) {}

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function run(string $matrixPath, string $outputDir, array $options = []): array
    {
        $matrixPath = realpath($matrixPath) ?: $matrixPath;
        $matrix = ComparatorMatrix::fromJsonFile($matrixPath);
        $benchmarkRoot = dirname($matrixPath, 2);
        $repositoryRoot = dirname($benchmarkRoot);
        $outputDir = rtrim($outputDir, DIRECTORY_SEPARATOR);
        $workerPath = (string) ($options['worker_path'] ?? ($benchmarkRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'comparator-worker.php'));

        $this->ensureFreshOutputDirectory($outputDir);
        $this->ensureDirectory($outputDir);

        $preflight = is_array($options['preflight'] ?? null)
            ? $options['preflight']
            : ComparatorPreflight::current($repositoryRoot, $matrixPath);

        $this->writeJson($outputDir . DIRECTORY_SEPARATOR . 'preflight.json', $preflight);

        if (($preflight['status'] ?? 'mismatched') !== 'matched') {
            $manifest = $this->manifest(
                'preflight_rejected',
                $repositoryRoot,
                $matrixPath,
                $preflight,
                max(1, (int) ($options['samples'] ?? 100)),
                max(0, (int) ($options['warmups'] ?? 5)),
                max(1, (int) ($options['request_count'] ?? 25)),
                [],
                [],
                $workerPath,
                [],
            );
            $this->writeJson($outputDir . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);

            return $manifest;
        }

        $rawDir = $outputDir . DIRECTORY_SEPARATOR . 'raw';
        $normalizedDir = $outputDir . DIRECTORY_SEPARATOR . 'normalized';
        $this->ensureDirectory($rawDir);
        $this->ensureDirectory($normalizedDir);

        $selectedComparators = $this->selectComparators($matrix, $options['comparators'] ?? null);
        $selectedScenarios = $this->selectScenarios($matrix, $options['scenarios'] ?? null);
        $samples = max(1, (int) ($options['samples'] ?? 100));
        $warmups = max(0, (int) ($options['warmups'] ?? 5));
        $requestCount = max(1, (int) ($options['request_count'] ?? 25));
        $matrixHash = (string) hash_file('sha256', $matrixPath);
        $results = [];
        $executionOrder = [];
        $bootWorkerExecutionSequence = [];
        $executionIndex = 0;
        $overallStatus = 'completed';

        if (in_array('application_boot', $selectedScenarios, true)) {
            $bootRawResults = $this->runApplicationBootScenarioSamples(
                $benchmarkRoot,
                $matrixPath,
                $workerPath,
                $selectedComparators,
                $samples,
                $requestCount,
                $preflight,
                $bootWorkerExecutionSequence,
            );

            foreach ($selectedComparators as $comparatorId => $definition) {
                if (!isset($bootRawResults[$comparatorId])) {
                    continue;
                }

                ++$executionIndex;
                $executionOrder[] = $comparatorId . ':application_boot';
                $result = $this->writeScenarioResult(
                    $outputDir,
                    'raw/' . $comparatorId . '-application_boot.json',
                    'normalized/' . $comparatorId . '-application_boot.json',
                    $bootRawResults[$comparatorId],
                    $definition,
                    $preflight,
                    $matrixHash,
                    $executionIndex,
                );

                if (($result['availability'] ?? null) === 'failed') {
                    $overallStatus = 'failed';
                }

                $results[] = $result;
            }
        }

        foreach ($selectedComparators as $comparatorId => $definition) {
            foreach ($selectedScenarios as $scenarioId) {
                if ($scenarioId === 'application_boot') {
                    continue;
                }

                if (!in_array($scenarioId, $definition['scenarios'], true)) {
                    continue;
                }

                ++$executionIndex;
                $executionOrder[] = $comparatorId . ':' . $scenarioId;
                $rawRelativePath = 'raw/' . $comparatorId . '-' . $scenarioId . '.json';
                $normalizedRelativePath = 'normalized/' . $comparatorId . '-' . $scenarioId . '.json';
                $raw = $this->runScenarioSamples($benchmarkRoot, $matrixPath, $workerPath, $comparatorId, $scenarioId, $samples, $warmups, $requestCount, $preflight);
                $result = $this->writeScenarioResult(
                    $outputDir,
                    $rawRelativePath,
                    $normalizedRelativePath,
                    $raw,
                    $definition,
                    $preflight,
                    $matrixHash,
                    $executionIndex,
                );

                if (($result['availability'] ?? null) === 'failed') {
                    $overallStatus = 'failed';
                }

                $results[] = $result;
            }
        }

        $manifest = $this->manifest($overallStatus, $repositoryRoot, $matrixPath, $preflight, $samples, $warmups, $requestCount, $executionOrder, $results, $workerPath, $bootWorkerExecutionSequence);
        $this->writeJson($outputDir . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);

        return $manifest;
    }

    /**
     * @param array<string, array<string, mixed>> $selectedComparators
     * @param array<string, mixed> $preflight
     * @param list<array<string, mixed>> $bootWorkerExecutionSequence
     * @return array<string, array<string, mixed>>
     */
    private function runApplicationBootScenarioSamples(
        string $benchmarkRoot,
        string $matrixPath,
        string $workerPath,
        array $selectedComparators,
        int $samples,
        int $requestCount,
        array $preflight,
        array &$bootWorkerExecutionSequence,
    ): array {
        $comparatorIds = [];
        $rawByComparator = [];
        $terminalComparators = [];

        foreach ($selectedComparators as $comparatorId => $definition) {
            if (!in_array('application_boot', $definition['scenarios'], true)) {
                continue;
            }

            $comparatorIds[] = $comparatorId;
            $rawByComparator[$comparatorId] = $this->initialApplicationBootRaw($comparatorId, $requestCount);
        }

        for ($sampleIndex = 1; $sampleIndex <= $samples; ++$sampleIndex) {
            foreach ($this->rotatedComparatorIds($comparatorIds, $sampleIndex) as $comparatorId) {
                if (($terminalComparators[$comparatorId] ?? false) === true) {
                    continue;
                }

                $discardCommand = $this->workerCommand($workerPath, $matrixPath, $comparatorId, 'application_boot', 0, $requestCount, $sampleIndex);
                $discardProcess = $this->runProcess($discardCommand, $benchmarkRoot);
                $discardRaw = $this->decodeRawOutput($discardProcess['stdout'], $discardProcess['stderr'], $discardProcess['exit_code'], $comparatorId, 'application_boot');
                $discardRaw['exit_code'] = $discardProcess['exit_code'];
                $discardEvidence = $this->workerExecutionEvidence(count($bootWorkerExecutionSequence) + 1, 'discarded', $comparatorId, 'application_boot', $sampleIndex, $discardRaw, $discardProcess['exit_code']);
                $bootWorkerExecutionSequence[] = $discardEvidence;
                $rawByComparator[$comparatorId]['discarded_worker_provenance'][] = $this->discardedWorkerProvenance($discardEvidence, $discardCommand, $discardRaw);

                $discardFailure = $this->bootWorkerFailure($discardRaw, $discardProcess['exit_code'], $preflight, 'discarded');

                if ($discardFailure !== null) {
                    $rawByComparator[$comparatorId] = $this->failedApplicationBootRaw($comparatorId, [], max(1, $discardProcess['exit_code']), $discardFailure, $discardRaw, $rawByComparator[$comparatorId], $bootWorkerExecutionSequence);
                    $terminalComparators[$comparatorId] = true;
                    continue;
                }

                if (($discardRaw['availability'] ?? null) === 'unavailable') {
                    if (count($rawByComparator[$comparatorId]['samples'] ?? []) > 0) {
                        $rawByComparator[$comparatorId] = $this->failedApplicationBootRaw(
                            $comparatorId,
                            [],
                            max(1, $discardProcess['exit_code']),
                            'application_boot availability changed during controlled boot sampling after accepted measured sample',
                            $discardRaw,
                            $rawByComparator[$comparatorId],
                            $bootWorkerExecutionSequence,
                        );
                    } else {
                        $discardRaw['commands'] = [];
                        $discardRaw['exit_code'] = 0;
                        $rawByComparator[$comparatorId] = $this->unavailableApplicationBootRaw($discardRaw, $rawByComparator[$comparatorId], $bootWorkerExecutionSequence);
                    }
                    $terminalComparators[$comparatorId] = true;
                    continue;
                }

                $measuredCommand = $this->workerCommand($workerPath, $matrixPath, $comparatorId, 'application_boot', 0, $requestCount, $sampleIndex);
                $measuredProcess = $this->runProcess($measuredCommand, $benchmarkRoot);
                $measuredRaw = $this->decodeRawOutput($measuredProcess['stdout'], $measuredProcess['stderr'], $measuredProcess['exit_code'], $comparatorId, 'application_boot');
                $measuredRaw['exit_code'] = $measuredProcess['exit_code'];
                $measuredEvidence = $this->workerExecutionEvidence(count($bootWorkerExecutionSequence) + 1, 'measured', $comparatorId, 'application_boot', $sampleIndex, $measuredRaw, $measuredProcess['exit_code']);
                $bootWorkerExecutionSequence[] = $measuredEvidence;

                $measuredFailure = $this->bootWorkerFailure($measuredRaw, $measuredProcess['exit_code'], $preflight, 'measured');

                if ($measuredFailure !== null) {
                    $rawByComparator[$comparatorId] = $this->failedApplicationBootRaw($comparatorId, [$measuredCommand], max(1, $measuredProcess['exit_code']), $measuredFailure, $measuredRaw, $rawByComparator[$comparatorId], $bootWorkerExecutionSequence);
                    $terminalComparators[$comparatorId] = true;
                    continue;
                }

                if (($measuredRaw['availability'] ?? null) === 'unavailable') {
                    $rawByComparator[$comparatorId] = $this->failedApplicationBootRaw(
                        $comparatorId,
                        [],
                        max(1, $measuredProcess['exit_code']),
                        'application_boot inconsistent availability within measured boot slot after discarded worker was available',
                        $measuredRaw,
                        $rawByComparator[$comparatorId],
                        $bootWorkerExecutionSequence,
                    );
                    $terminalComparators[$comparatorId] = true;
                    continue;
                }

                $this->recordMeasuredApplicationBootSample($rawByComparator[$comparatorId], $measuredRaw, $measuredCommand);
            }
        }

        foreach ($rawByComparator as $comparatorId => $raw) {
            if (($raw['availability'] ?? null) === 'available') {
                $rawByComparator[$comparatorId]['sample_count'] = count($raw['samples']);
                $rawByComparator[$comparatorId]['boot_worker_execution_sequence'] = $this->filterBootWorkerSequence($bootWorkerExecutionSequence, $comparatorId);
            }
        }

        return $rawByComparator;
    }

    /**
     * @return array<string, mixed>
     */
    private function initialApplicationBootRaw(string $comparatorId, int $requestCount): array
    {
        return [
            'schema_version' => ComparatorScenarioExecutor::SCHEMA_VERSION,
            'comparator_id' => $comparatorId,
            'scenario_id' => 'application_boot',
            'availability' => 'available',
            'availability_status' => 'available',
            'sample_count' => 0,
            'samples' => [],
            'unit' => 'microseconds',
            'memory' => [],
            'warmups' => 0,
            'request_count' => $requestCount,
            'operations_per_sample' => 1,
            'samples_metadata' => [],
            'raw_samples' => [],
            'commands' => [],
            'exit_code' => 0,
            'boot_protocol' => $this->bootProtocol(),
            'discarded_worker_provenance' => [],
        ];
    }

    /**
     * @param list<string> $comparatorIds
     * @return list<string>
     */
    private function rotatedComparatorIds(array $comparatorIds, int $sampleIndex): array
    {
        $count = count($comparatorIds);

        if ($count === 0) {
            return [];
        }

        $start = ($sampleIndex - 1) % $count;

        return array_merge(array_slice($comparatorIds, $start), array_slice($comparatorIds, 0, $start));
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $preflight
     */
    private function bootWorkerFailure(array $raw, int $exitCode, array $preflight, string $role): ?string
    {
        $availability = $raw['availability'] ?? null;
        $expectedWorkerIdentityHash = $preflight['worker_environment_identity']['hash'] ?? null;
        $workerIdentityHash = $raw['worker_environment_identity']['hash'] ?? null;

        if (
            in_array($availability, ['available', 'unavailable'], true)
            && $expectedWorkerIdentityHash !== null
            && $workerIdentityHash !== $expectedWorkerIdentityHash
        ) {
            return $role === 'discarded'
                ? 'discarded boot worker runtime identity did not match accepted preflight lane'
                : 'measurement worker runtime identity did not match accepted preflight lane';
        }

        if ($availability !== 'available' && $availability !== 'unavailable') {
            return $role === 'discarded'
                ? (string) ($raw['reason'] ?? 'discarded boot worker failed before measured sample')
                : (string) ($raw['reason'] ?? 'comparator worker failed');
        }

        if ($exitCode !== 0) {
            return $role === 'discarded'
                ? (string) ($raw['reason'] ?? 'discarded boot worker failed before measured sample')
                : (string) ($raw['reason'] ?? 'comparator worker failed');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function workerExecutionEvidence(
        int $sequenceIndex,
        string $role,
        string $comparatorId,
        string $scenarioId,
        int $sampleIndex,
        array $raw,
        int $exitCode,
    ): array {
        return [
            'sequence_index' => $sequenceIndex,
            'role' => $role,
            'comparator_id' => $comparatorId,
            'scenario_id' => $scenarioId,
            'sample_index' => $sampleIndex,
            'excluded_from_statistics' => $role === 'discarded',
            'pid' => $raw['process']['pid'] ?? null,
            'worker_environment_identity_hash' => $raw['worker_environment_identity']['hash'] ?? null,
            'exit_code' => $exitCode,
            'availability' => $raw['availability'] ?? 'failed',
            'availability_status' => $raw['availability_status'] ?? null,
            'reason' => $raw['reason'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @param list<string> $command
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function discardedWorkerProvenance(array $evidence, array $command, array $raw): array
    {
        return [
            'sequence_index' => $evidence['sequence_index'],
            'role' => 'discarded',
            'comparator_id' => $evidence['comparator_id'],
            'scenario_id' => $evidence['scenario_id'],
            'sample_index' => $evidence['sample_index'],
            'pid' => $evidence['pid'],
            'worker_environment_identity_hash' => $evidence['worker_environment_identity_hash'],
            'exit_code' => $evidence['exit_code'],
            'availability' => $evidence['availability'],
            'availability_status' => $evidence['availability_status'],
            'duration_microseconds' => isset($raw['samples'][0]) ? (float) $raw['samples'][0] : null,
            'excluded_from_statistics' => true,
            'command' => $command,
            'reason' => $evidence['reason'],
        ];
    }

    /**
     * @param list<list<string>> $commands
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $previousRaw
     * @param list<array<string, mixed>> $bootWorkerExecutionSequence
     * @return array<string, mixed>
     */
    private function failedApplicationBootRaw(
        string $comparatorId,
        array $commands,
        int $exitCode,
        string $reason,
        array $raw,
        array $previousRaw,
        array $bootWorkerExecutionSequence,
    ): array {
        $failed = $previousRaw;
        $failed['schema_version'] = ComparatorScenarioExecutor::SCHEMA_VERSION;
        $failed['comparator_id'] = $comparatorId;
        $failed['scenario_id'] = 'application_boot';
        $failed['availability'] = 'failed';
        $failed['availability_status'] = 'failed';
        $failed['reason'] = $reason;
        $failed['sample_count'] = count(is_array($previousRaw['samples'] ?? null) ? $previousRaw['samples'] : []);
        $failed['samples'] = is_array($previousRaw['samples'] ?? null) ? array_values($previousRaw['samples']) : [];
        $failed['unit'] = 'microseconds';
        $failed['memory'] = is_array($previousRaw['memory'] ?? null) ? $previousRaw['memory'] : [];
        $failed['warmups'] = 0;
        $failed['request_count'] = $previousRaw['request_count'] ?? 1;
        $failed['operations_per_sample'] = 1;
        $failed['worker_environment_identity'] = $previousRaw['worker_environment_identity'] ?? ($raw['worker_environment_identity'] ?? null);
        $failed['dependency_context'] = $previousRaw['dependency_context'] ?? ($raw['dependency_context'] ?? null);
        $failed['samples_metadata'] = is_array($previousRaw['samples_metadata'] ?? null) ? $previousRaw['samples_metadata'] : [];
        $acceptedCommands = is_array($previousRaw['commands'] ?? null) ? $previousRaw['commands'] : [];
        $failed['commands'] = $acceptedCommands === [] ? $commands : $acceptedCommands;
        $failed['raw_samples'] = is_array($previousRaw['raw_samples'] ?? null) ? $previousRaw['raw_samples'] : [];
        $failed['exit_code'] = $exitCode;
        $failed['boot_protocol'] = $this->bootProtocol();
        $failed['discarded_worker_provenance'] = $previousRaw['discarded_worker_provenance'] ?? [];
        $failed['boot_worker_execution_sequence'] = $this->filterBootWorkerSequence($bootWorkerExecutionSequence, $comparatorId);

        return $failed;
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $previousRaw
     * @param list<array<string, mixed>> $bootWorkerExecutionSequence
     * @return array<string, mixed>
     */
    private function unavailableApplicationBootRaw(array $raw, array $previousRaw, array $bootWorkerExecutionSequence): array
    {
        $raw['boot_protocol'] = $this->bootProtocol();
        $raw['raw_samples'] = $raw['raw_samples'] ?? [];
        $raw['discarded_worker_provenance'] = $previousRaw['discarded_worker_provenance'] ?? [];
        $raw['boot_worker_execution_sequence'] = $this->filterBootWorkerSequence($bootWorkerExecutionSequence, (string) ($raw['comparator_id'] ?? ''));

        return $raw;
    }

    /**
     * @param array<string, mixed> $aggregate
     * @param array<string, mixed> $raw
     * @param list<string> $command
     */
    private function recordMeasuredApplicationBootSample(array &$aggregate, array $raw, array $command): void
    {
        $sampleValue = (float) (($raw['samples'][0] ?? null) ?? 0.0);
        $sampleRecord = $raw;
        $rawSampleHash = $this->hashJson($sampleRecord);
        $aggregate['samples'][] = $sampleValue;
        $aggregate['timing_boundary'] = $raw['subject_metadata']['timing_boundary'] ?? ($raw['timing_boundary'] ?? null);
        $aggregate['prepared_framework_instance_count'] = $raw['subject_metadata']['prepared_framework_instance_count'] ?? ($raw['prepared_framework_instance_count'] ?? null);
        $aggregate['worker_environment_identity'] = $raw['worker_environment_identity'] ?? ($aggregate['worker_environment_identity'] ?? null);
        $aggregate['dependency_context'] = $raw['dependency_context'] ?? ($aggregate['dependency_context'] ?? null);
        $aggregate['commands'][] = $command;
        $aggregate['raw_samples'][] = [
            'sample_index' => $raw['sample_index'] ?? (count($aggregate['samples']) + 1),
            'sha256' => $rawSampleHash,
            'record' => $sampleRecord,
        ];
        $aggregate['samples_metadata'][] = [
            'sample_index' => $raw['sample_index'] ?? count($aggregate['samples']),
            'pid' => $raw['process']['pid'] ?? null,
            'environment_identity_hash' => $raw['worker_environment_identity']['hash'] ?? null,
            'worker_environment_identity_hash' => $raw['worker_environment_identity']['hash'] ?? null,
            'raw_sample_sha256' => $rawSampleHash,
            'duration_microseconds' => $sampleValue,
        ];
        $aggregate['last_result'] = $raw['last_result'] ?? ($aggregate['last_result'] ?? []);

        foreach (is_array($raw['memory'] ?? null) ? $raw['memory'] : [] as $key => $value) {
            $aggregate['memory'][$key] = max((int) ($aggregate['memory'][$key] ?? 0), (int) $value);
        }
    }

    /**
     * @param list<array<string, mixed>> $bootWorkerExecutionSequence
     * @return list<array<string, mixed>>
     */
    private function filterBootWorkerSequence(array $bootWorkerExecutionSequence, string $comparatorId): array
    {
        return array_values(array_filter(
            $bootWorkerExecutionSequence,
            static fn(array $entry): bool => ($entry['comparator_id'] ?? null) === $comparatorId,
        ));
    }


    /**
     * @param array<string, mixed> $preflight
     * @return array<string, mixed>
     */
    private function runScenarioSamples(
        string $benchmarkRoot,
        string $matrixPath,
        string $workerPath,
        string $comparatorId,
        string $scenarioId,
        int $samples,
        int $warmups,
        int $requestCount,
        array $preflight,
    ): array {
        $sampleValues = [];
        $samplesMetadata = [];
        $commands = [];
        $rawSamples = [];
        $memory = [];
        $lastResult = [];
        $subjectMetadata = [];
        $workerEnvironmentIdentity = null;
        $dependencyContext = null;
        $expectedWorkerIdentityHash = $preflight['worker_environment_identity']['hash'] ?? null;

        for ($sampleIndex = 1; $sampleIndex <= $samples; ++$sampleIndex) {
            $command = $this->workerCommand($workerPath, $matrixPath, $comparatorId, $scenarioId, $warmups, $requestCount, $sampleIndex);
            $commands[] = $command;
            $processResult = $this->runProcess($command, $benchmarkRoot);
            $raw = $this->decodeRawOutput($processResult['stdout'], $processResult['stderr'], $processResult['exit_code'], $comparatorId, $scenarioId);
            $raw['exit_code'] = $processResult['exit_code'];
            $availability = $raw['availability'] ?? null;
            $workerIdentityHash = $raw['worker_environment_identity']['hash'] ?? null;

            if (
                in_array($availability, ['available', 'unavailable'], true)
                && $expectedWorkerIdentityHash !== null
                && $workerIdentityHash !== $expectedWorkerIdentityHash
            ) {
                return $this->failedRaw(
                    $comparatorId,
                    $scenarioId,
                    $commands,
                    1,
                    'measurement worker runtime identity did not match accepted preflight lane',
                    $raw,
                );
            }

            if ($availability === 'unavailable') {
                $raw['commands'] = $commands;
                $raw['exit_code'] = 0;

                return $raw;
            }

            if ($availability !== 'available' || $processResult['exit_code'] !== 0) {
                return $this->failedRaw($comparatorId, $scenarioId, $commands, $processResult['exit_code'], (string) ($raw['reason'] ?? 'comparator worker failed'), $raw);
            }

            $sampleValues[] = (float) (($raw['samples'][0] ?? null) ?? 0.0);
            $workerEnvironmentIdentity = $raw['worker_environment_identity'] ?? $workerEnvironmentIdentity;
            $dependencyContext = $raw['dependency_context'] ?? $dependencyContext;
            $subjectMetadata = $raw['subject_metadata'] ?? $subjectMetadata;
            $lastResult = $raw['last_result'] ?? $lastResult;
            $sampleRecord = $raw;
            $rawSampleHash = $this->hashJson($sampleRecord);
            $rawSamples[] = [
                'sample_index' => $sampleIndex,
                'sha256' => $rawSampleHash,
                'record' => $sampleRecord,
            ];

            foreach (is_array($raw['memory'] ?? null) ? $raw['memory'] : [] as $key => $value) {
                $memory[$key] = max((int) ($memory[$key] ?? 0), (int) $value);
            }

            $samplesMetadata[] = [
                'sample_index' => $sampleIndex,
                'pid' => $raw['process']['pid'] ?? null,
                'environment_identity_hash' => $workerIdentityHash,
                'worker_environment_identity_hash' => $workerIdentityHash,
                'raw_sample_sha256' => $rawSampleHash,
                'duration_microseconds' => (float) end($sampleValues),
            ];
        }

        return [
            'schema_version' => ComparatorScenarioExecutor::SCHEMA_VERSION,
            'comparator_id' => $comparatorId,
            'scenario_id' => $scenarioId,
            'availability' => 'available',
            'availability_status' => 'available',
            'timing_boundary' => $subjectMetadata['timing_boundary'] ?? null,
            'prepared_framework_instance_count' => $subjectMetadata['prepared_framework_instance_count'] ?? null,
            'sample_count' => count($sampleValues),
            'samples' => $sampleValues,
            'unit' => 'microseconds',
            'memory' => $memory,
            'warmups' => $warmups,
            'request_count' => $requestCount,
            'operations_per_sample' => $scenarioId === 'http_repeated_warm' ? $requestCount : 1,
            'worker_environment_identity' => $workerEnvironmentIdentity,
            'dependency_context' => $dependencyContext,
            'samples_metadata' => $samplesMetadata,
            'raw_samples' => $rawSamples,
            'commands' => $commands,
            'exit_code' => 0,
            'last_result' => $lastResult,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $preflight
     * @return array<string, mixed>
     */
    private function writeScenarioResult(
        string $outputDir,
        string $rawRelativePath,
        string $normalizedRelativePath,
        array $raw,
        array $definition,
        array $preflight,
        string $matrixHash,
        int $executionIndex,
    ): array {
        $this->writeJson($outputDir . DIRECTORY_SEPARATOR . $rawRelativePath, $raw);
        $rawHash = (string) hash_file('sha256', $outputDir . DIRECTORY_SEPARATOR . $rawRelativePath);

        $normalized = $this->normalizeRawResult($raw, $definition, $preflight, $matrixHash, $rawHash);
        $this->writeJson($outputDir . DIRECTORY_SEPARATOR . $normalizedRelativePath, $normalized);
        $normalizedHash = (string) hash_file('sha256', $outputDir . DIRECTORY_SEPARATOR . $normalizedRelativePath);

        $result = [
            'execution_index' => $executionIndex,
            'comparator_id' => $definition['id'],
            'framework_name' => $definition['name'],
            'framework_version' => $definition['framework_version'],
            'scenario_id' => $raw['scenario_id'] ?? null,
            'implementation_model' => $definition['implementation_model'],
            'availability' => $raw['availability'] ?? 'failed',
            'reason' => $raw['reason'] ?? null,
            'timing_boundary' => $raw['timing_boundary'] ?? null,
            'sample_count' => (int) ($raw['sample_count'] ?? 0),
            'operations_per_sample' => (int) ($raw['operations_per_sample'] ?? 1),
            'samples_metadata' => $raw['samples_metadata'] ?? [],
            'raw_samples' => $raw['raw_samples'] ?? [],
            'execution_environment_identity' => $this->executionEnvironmentIdentity($preflight),
            'execution_environment_fingerprint' => $this->executionEnvironmentIdentity($preflight)['hash'],
            'worker_environment_identity' => $raw['worker_environment_identity'] ?? null,
            'dependency_context' => $raw['dependency_context'] ?? null,
            'command' => $raw['commands'][0] ?? [],
            'commands' => $raw['commands'] ?? [],
            'exit_code' => (int) ($raw['exit_code'] ?? 1),
            'raw_result' => [
                'path' => $rawRelativePath,
                'sha256' => $rawHash,
            ],
            'normalized_result' => [
                'path' => $normalizedRelativePath,
                'sha256' => $normalizedHash,
            ],
            'fixture_identity_hash' => $normalized['fixture_identity_hash'] ?? null,
            'comparator_lock_sha256' => $definition['lock_sha256'] ?? null,
        ];

        foreach (['boot_protocol', 'boot_worker_execution_sequence', 'discarded_worker_provenance'] as $key) {
            if (array_key_exists($key, $raw)) {
                $result[$key] = $raw[$key];
            }
        }

        return $result;
    }

    /**
     * @param list<list<string>> $commands
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function failedRaw(string $comparatorId, string $scenarioId, array $commands, int $exitCode, string $reason, array $raw = []): array
    {
        return [
            'schema_version' => ComparatorScenarioExecutor::SCHEMA_VERSION,
            'comparator_id' => $comparatorId,
            'scenario_id' => $scenarioId,
            'availability' => 'failed',
            'availability_status' => 'failed',
            'reason' => $reason,
            'sample_count' => 0,
            'samples' => [],
            'unit' => 'microseconds',
            'worker_environment_identity' => $raw['worker_environment_identity'] ?? null,
            'dependency_context' => $raw['dependency_context'] ?? null,
            'samples_metadata' => [],
            'commands' => $commands,
            'exit_code' => $exitCode,
        ];
    }

    /**
     * @param array<string, mixed> $preflight
     * @param list<string> $executionOrder
     * @param list<array<string, mixed>> $results
     * @param list<array<string, mixed>> $bootWorkerExecutionSequence
     * @return array<string, mixed>
     */
    private function manifest(
        string $status,
        string $repositoryRoot,
        string $matrixPath,
        array $preflight,
        int $samples,
        int $warmups,
        int $requestCount,
        array $executionOrder,
        array $results,
        string $workerPath,
        array $bootWorkerExecutionSequence,
    ): array {
        return [
            'schema_version' => self::MANIFEST_SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
            'source' => $preflight['environment']['source'] ?? null,
            'matrix' => [
                'path' => $this->relativePath($repositoryRoot, $matrixPath),
                'sha256' => hash_file('sha256', $matrixPath),
            ],
            'preflight' => $preflight,
            'environment_identity' => $this->executionEnvironmentIdentity($preflight),
            'execution_environment_identity' => $this->executionEnvironmentIdentity($preflight),
            'execution_environment_fingerprint' => $this->executionEnvironmentIdentity($preflight)['hash'],
            'worker_environment_identity' => $preflight['worker_environment_identity'] ?? null,
            'process_isolation' => [
                'model' => 'subprocess_per_measured_sample',
                'worker' => $this->relativePath($repositoryRoot, $workerPath),
                'php_binary' => $this->phpBinary,
            ],
            'samples' => $samples,
            'warmups' => $warmups,
            'request_count' => $requestCount,
            'boot_protocol' => $this->bootProtocol(),
            'boot_worker_execution_sequence' => $bootWorkerExecutionSequence,
            'execution_order' => $executionOrder,
            'results' => $results,
        ];
    }

    /**
     * @param mixed $requested
     * @return array<string, array<string, mixed>>
     */
    private function selectComparators(ComparatorMatrix $matrix, mixed $requested): array
    {
        $comparators = $matrix->comparators();

        if ($requested === null || $requested === [] || $requested === 'all') {
            return $comparators;
        }

        $ids = is_array($requested) ? $requested : explode(',', (string) $requested);
        $selected = [];

        foreach ($ids as $id) {
            $id = trim((string) $id);

            if ($id === '') {
                continue;
            }

            if (!isset($comparators[$id])) {
                throw new RuntimeException("Unknown comparator '{$id}'.");
            }

            $selected[$id] = $comparators[$id];
        }

        return $selected;
    }

    /**
     * @param mixed $requested
     * @return list<string>
     */
    private function selectScenarios(ComparatorMatrix $matrix, mixed $requested): array
    {
        $scenarios = array_keys($matrix->commonScenarios());

        if ($requested === null || $requested === [] || $requested === 'all') {
            return $scenarios;
        }

        $requestedIds = is_array($requested) ? $requested : explode(',', (string) $requested);
        $selected = [];

        foreach ($scenarios as $scenarioId) {
            if (in_array($scenarioId, $requestedIds, true)) {
                $selected[] = $scenarioId;
            }
        }

        foreach ($requestedIds as $scenarioId) {
            if (!in_array($scenarioId, $scenarios, true)) {
                throw new RuntimeException("Unknown scenario '{$scenarioId}'.");
            }
        }

        return $selected;
    }

    /**
     * @return list<string>
     */
    private function workerCommand(string $workerPath, string $matrixPath, string $comparatorId, string $scenarioId, int $warmups, int $requestCount, int $sampleIndex): array
    {
        return [
            $this->phpBinary,
            $workerPath,
            '--matrix=' . $matrixPath,
            '--comparator=' . $comparatorId,
            '--scenario=' . $scenarioId,
            '--warmups=' . $warmups,
            '--request-count=' . $requestCount,
            '--sample-index=' . $sampleIndex,
        ];
    }

    /**
     * @param list<string> $command
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    private function runProcess(array $command, string $cwd): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start comparator worker process.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => proc_close($process),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeRawOutput(string $stdout, string $stderr, int $exitCode, string $comparatorId, string $scenarioId): array
    {
        try {
            $decoded = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }

        if (is_array($decoded)) {
            return $decoded;
        }

        return [
            'schema_version' => ComparatorScenarioExecutor::SCHEMA_VERSION,
            'comparator_id' => $comparatorId,
            'scenario_id' => $scenarioId,
            'availability' => 'failed',
            'availability_status' => 'failed',
            'reason' => trim($stderr) !== '' ? trim($stderr) : 'comparator worker failed without JSON output',
            'exit_code' => $exitCode,
            'sample_count' => 0,
            'samples' => [],
            'unit' => 'microseconds',
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $preflight
     * @return array<string, mixed>
     */
    private function normalizeRawResult(array $raw, array $definition, array $preflight, string $matrixHash, string $rawHash): array
    {
        $fixtureIdentity = FixtureIdentity::fromArray([
            'comparator_id' => $definition['id'],
            'framework_name' => $definition['name'],
            'framework_version' => $definition['framework_version'],
            'fixture_version' => $definition['fixture_version'] ?? null,
            'lock_hash' => $definition['lock_sha256'] ?? null,
            'configuration' => $definition['configuration'] ?? [],
        ]);
        $metadata = [
            'id' => $definition['id'],
            'name' => $definition['name'],
            'framework_version' => $definition['framework_version'],
            'fixture_identity' => $fixtureIdentity,
            'execution_environment_identity' => $this->executionEnvironmentIdentity($preflight),
            'execution_environment_fingerprint' => $this->executionEnvironmentIdentity($preflight)['hash'],
            'worker_environment_identity' => $raw['worker_environment_identity'] ?? ($preflight['worker_environment_identity'] ?? null),
            'source_evolvephp_sha' => $preflight['environment']['source']['git_sha'] ?? null,
            'source_dirty' => $preflight['environment']['source']['dirty'] ?? null,
            'scenario_id' => $raw['scenario_id'] ?? null,
            'availability' => $raw['availability'] ?? 'failed',
            'availability_status' => $raw['availability_status'] ?? null,
            'availability_reason' => $raw['reason'] ?? null,
            'failure_reason' => $raw['reason'] ?? null,
            'implementation_model' => $definition['implementation_model'] ?? null,
            'matrix_sha256' => $matrixHash,
            'comparator_lock_sha256' => $definition['lock_sha256'] ?? null,
            'raw_evidence_sha256' => $rawHash,
            'operations_per_sample' => $raw['operations_per_sample'] ?? 1,
        ];

        if (($raw['availability'] ?? null) === 'unavailable') {
            return ComparatorResultNormalizer::unavailable($metadata);
        }

        if (($raw['availability'] ?? null) !== 'available') {
            return ComparatorResultNormalizer::failed($metadata);
        }

        $normalized = ComparatorResultNormalizer::normalizeRawScenario($raw, [
            'source' => $preflight['environment']['source'] ?? [],
            'fingerprint' => $metadata['execution_environment_identity'],
        ]);

        return ComparatorResultNormalizer::withComparatorMetadata($normalized, $metadata);
    }

    /**
     * @param array<string, mixed> $preflight
     * @return array{hash: string, fields: array<string, mixed>}
     */
    private function executionEnvironmentIdentity(array $preflight): array
    {
        if (isset($preflight['execution_environment_identity']) && is_array($preflight['execution_environment_identity'])) {
            return $preflight['execution_environment_identity'];
        }

        return ExecutionEnvironmentFingerprint::fromEnvironment(is_array($preflight['environment'] ?? null) ? $preflight['environment'] : []);
    }

    /**
     * @return array<string, mixed>
     */
    private function bootProtocol(): array
    {
        return [
            'discarded_worker_processes_per_measured_sample' => 1,
            'sample_order' => 'rotating_round_robin',
            'measured_worker_in_process_warmups' => 0,
            'outlier_policy' => 'retain_all_measured_samples',
            'primary_central_statistic' => 'p50',
        ];
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException("Unable to create directory: {$path}");
        }
    }

    private function ensureFreshOutputDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = array_diff(scandir($path) ?: [], ['.', '..']);

        if ($files !== []) {
            throw new RuntimeException('Comparator output directory must be absent or empty before a run begins.');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $this->ensureDirectory(dirname($path));
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hashJson(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    }

    private function relativePath(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
    }
}
