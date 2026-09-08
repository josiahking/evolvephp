<?php

declare(strict_types=1);

namespace Evolve\DevTools\Audit\Project;

use Evolve\DevTools\Audit\AuditFinding;
use Evolve\DevTools\Audit\AuditInspector;
use Evolve\DevTools\Audit\AuditSeverity;
use JsonException;

/**
 * @experimental
 */
final readonly class ComposerLockInspector implements AuditInspector
{
    /**
     * @return list<AuditFinding>
     */
    public function inspect(string $projectRoot): array
    {
        $lockPath = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer.lock';

        if (! is_file($lockPath) || ! is_readable($lockPath)) {
            return [
                new AuditFinding('composer_lock.unavailable', AuditSeverity::Warning, 'composer.lock is missing or unreadable.', [
                    'path' => 'composer.lock',
                    'reason' => 'missing or unreadable',
                    'complete' => false,
                ]),
            ];
        }

        $content = file_get_contents($lockPath);

        if ($content === false) {
            return [
                new AuditFinding('composer_lock.unavailable', AuditSeverity::Warning, 'composer.lock is missing or unreadable.', [
                    'path' => 'composer.lock',
                    'reason' => 'missing or unreadable',
                    'complete' => false,
                ]),
            ];
        }

        try {
            $root = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return [
                new AuditFinding('composer_lock.invalid_json', AuditSeverity::Risk, 'composer.lock is not valid JSON.', [
                    'path' => 'composer.lock',
                    'error' => $exception->getMessage(),
                    'complete' => false,
                ]),
            ];
        }

        if (! $root instanceof \stdClass) {
            return [
                new AuditFinding('composer_lock.root_not_object', AuditSeverity::Risk, 'composer.lock root must be a JSON object.', [
                    'path' => 'composer.lock',
                    'actual' => $this->typeOf($root),
                    'complete' => false,
                ]),
            ];
        }

        $runtime = $this->packageSection($root, 'packages', 'runtime');
        $development = $this->packageSection($root, 'packages-dev', 'development');
        $rootPlatform = $this->rootPlatform($root, 'platform', 'root-runtime', 'runtime');
        $rootPlatformDevelopment = $this->rootPlatform($root, 'platform-dev', 'root-development', 'development');
        $issues = [
            ...$runtime['issues'],
            ...$development['issues'],
            ...$rootPlatform['issues'],
            ...$rootPlatformDevelopment['issues'],
        ];
        $packages = [...$runtime['packages'], ...$development['packages']];
        $findings = [];

        if ($issues !== []) {
            $findings[] = new AuditFinding('composer_lock.malformed', AuditSeverity::Risk, 'composer.lock contains structurally incomplete evidence.', [
                'issues' => $this->sortIssues($issues),
                'complete' => false,
                'sort' => 'section then package then field then requirement then reason then actual ascending',
            ]);
        }

        $findings[] = $this->inventoryFinding($runtime, $development);
        $findings[] = $this->requirementGraphFinding($packages, $runtime['state'], $development['state']);
        $findings[] = $this->platformRequirementsFinding(
            $packages,
            [...$rootPlatform['requirements'], ...$rootPlatformDevelopment['requirements']],
            $runtime['state'],
            $development['state'],
            $rootPlatform['state'],
            $rootPlatformDevelopment['state'],
        );

        $pluginFinding = $this->composerPluginsFinding($packages, $runtime['state'], $development['state']);

        if ($pluginFinding !== null) {
            $findings[] = $pluginFinding;
        }

        $abandonedFinding = $this->abandonedPackagesFinding($packages, $runtime['state'], $development['state']);

        if ($abandonedFinding !== null) {
            $findings[] = $abandonedFinding;
        }

        return $findings;
    }

    /**
     * @return array{state: 'absent'|'valid'|'partial'|'unknown', complete: bool, packages: list<array{name: string, version: string, scope: string, require: array<string, string>|null, type: string|null, abandoned: bool|string|null, abandoned_malformed: bool}>, issues: list<array<string, string>>}
     */
    private function packageSection(\stdClass $root, string $field, string $scope): array
    {
        if (! property_exists($root, $field)) {
            return ['state' => 'absent', 'complete' => true, 'packages' => [], 'issues' => []];
        }

        $section = $root->{$field};

        if (! is_array($section) || ! array_is_list($section)) {
            return [
                'state' => 'unknown',
                'complete' => false,
                'packages' => [],
                'issues' => [[
                    'section' => $field,
                    'field' => $field,
                    'reason' => 'must be a list',
                    'actual' => $this->typeOf($section),
                ]],
            ];
        }

        $packages = [];
        $issues = [];
        $entries = $this->canonicalPackageEntries($section);

        foreach ($entries as $entry) {
            if (! $entry instanceof \stdClass) {
                $issues[] = [
                    'section' => $field,
                    'field' => 'package',
                    'reason' => 'must be a JSON object',
                    'actual' => $this->typeOf($entry),
                ];

                continue;
            }

            $nameIssue = $this->requiredStringIssue($entry, 'name', $field);
            $versionIssue = $this->requiredStringIssue($entry, 'version', $field);

            if ($nameIssue !== null) {
                $issues[] = $nameIssue;
            }

            if ($versionIssue !== null) {
                if (is_string($entry->name ?? null) && $entry->name !== '') {
                    $versionIssue['package'] = $entry->name;
                }

                $issues[] = $versionIssue;
            }

            if ($nameIssue !== null || $versionIssue !== null) {
                continue;
            }

            $package = [
                'name' => $entry->name,
                'version' => $entry->version,
                'scope' => $scope,
                'require' => null,
                'type' => null,
                'abandoned' => null,
                'abandoned_malformed' => false,
            ];

            if (property_exists($entry, 'type') && is_string($entry->type)) {
                $package['type'] = $entry->type;
            } elseif (property_exists($entry, 'type')) {
                $issues[] = [
                    'section' => $field,
                    'package' => $entry->name,
                    'field' => 'type',
                    'reason' => 'must be a string when present',
                    'actual' => $this->typeOf($entry->type),
                ];
            }

            if (property_exists($entry, 'require')) {
                if ($entry->require instanceof \stdClass) {
                    $require = $this->requirementEntries($entry->require, $field, $entry->name);
                    $package['require'] = $require['requirements'];
                    $issues = [...$issues, ...$require['issues']];
                } else {
                    $issues[] = [
                        'section' => $field,
                        'package' => $entry->name,
                        'field' => 'require',
                        'reason' => 'must be a JSON object when present',
                        'actual' => $this->typeOf($entry->require),
                    ];
                }
            }

            if (property_exists($entry, 'abandoned')) {
                if ($entry->abandoned === true || $entry->abandoned === false || (is_string($entry->abandoned) && $entry->abandoned !== '')) {
                    $package['abandoned'] = $entry->abandoned;
                } else {
                    $package['abandoned_malformed'] = true;
                    $issues[] = [
                        'section' => $field,
                        'package' => $entry->name,
                        'field' => 'abandoned',
                        'reason' => 'must be boolean or non-empty string',
                        'actual' => $entry->abandoned === '' ? 'empty string' : $this->typeOf($entry->abandoned),
                    ];
                }
            }

            $packages[] = $package;
        }

        $this->sortParsedPackages($packages);

        if ($issues !== []) {
            return ['state' => 'partial', 'complete' => false, 'packages' => $packages, 'issues' => $issues];
        }

        return ['state' => 'valid', 'complete' => true, 'packages' => $packages, 'issues' => []];
    }

    /**
     * @param list<mixed> $entries
     * @return list<mixed>
     */
    private function canonicalPackageEntries(array $entries): array
    {
        usort($entries, function (mixed $left, mixed $right): int {
            return $this->packageEntrySortKey($left) <=> $this->packageEntrySortKey($right);
        });

        return $entries;
    }

    /**
     * @return list<string>
     */
    private function packageEntrySortKey(mixed $entry): array
    {
        if (! $entry instanceof \stdClass) {
            return ['1', $this->typeOf($entry)];
        }

        $name = property_exists($entry, 'name') && is_string($entry->name) && $entry->name !== '' ? $entry->name : '~';
        $version = property_exists($entry, 'version') && is_string($entry->version) && $entry->version !== '' ? $entry->version : '~';

        return [$name, $version];
    }

    /**
     * @return array<string, string>|null
     */
    private function requiredStringIssue(\stdClass $entry, string $field, string $section): ?array
    {
        if (! property_exists($entry, $field)) {
            return [
                'section' => $section,
                'field' => $field,
                'reason' => 'must be a non-empty string',
                'actual' => 'missing',
            ];
        }

        $value = $entry->{$field};

        if (! is_string($value) || $value === '') {
            return [
                'section' => $section,
                'field' => $field,
                'reason' => 'must be a non-empty string',
                'actual' => $value === '' ? 'empty string' : $this->typeOf($value),
            ];
        }

        return null;
    }

    /**
     * @return array{state: 'absent'|'valid'|'partial'|'unknown', requirements: list<array{source: string, scope: string, requirement: string, constraint: string}>, issues: list<array<string, string>>}
     */
    private function rootPlatform(\stdClass $root, string $field, string $source, string $scope): array
    {
        if (! property_exists($root, $field)) {
            return ['state' => 'absent', 'requirements' => [], 'issues' => []];
        }

        $platform = $root->{$field};

        if (! $platform instanceof \stdClass) {
            return [
                'state' => 'unknown',
                'requirements' => [],
                'issues' => [[
                    'section' => $field,
                    'field' => $field,
                    'reason' => 'must be a JSON object',
                    'actual' => $this->typeOf($platform),
                ]],
            ];
        }

        $requirements = [];
        $issues = [];
        $entries = get_object_vars($platform);

        ksort($entries, SORT_STRING);

        foreach ($entries as $requirement => $constraint) {
            if (! is_string($constraint)) {
                $issues[] = [
                    'section' => $field,
                    'field' => $requirement,
                    'reason' => 'constraint must be a string',
                    'actual' => $this->typeOf($constraint),
                ];

                continue;
            }

            $requirements[] = [
                'source' => $source,
                'scope' => $scope,
                'requirement' => $requirement,
                'constraint' => $constraint,
            ];
        }

        return ['state' => $issues === [] ? 'valid' : 'partial', 'requirements' => $requirements, 'issues' => $issues];
    }

    /**
     * @param array{state: string, complete: bool, packages: list<array{name: string, version: string, scope: string}>} $runtime
     * @param array{state: string, complete: bool, packages: list<array{name: string, version: string, scope: string}>} $development
     */
    private function inventoryFinding(array $runtime, array $development): AuditFinding
    {
        $complete = $runtime['complete'] && $development['complete'];

        return new AuditFinding('composer_lock.package_inventory', $complete ? AuditSeverity::Info : AuditSeverity::Warning, 'Locked package inventory evidence was inspected.', [
            'runtime' => [
                'state' => $runtime['state'],
                'complete' => $runtime['complete'],
                'packages' => $this->inventoryPackages($runtime['packages']),
            ],
            'development' => [
                'state' => $development['state'],
                'complete' => $development['complete'],
                'packages' => $this->inventoryPackages($development['packages']),
            ],
            'complete' => $complete,
            'sort' => 'section scope then package name then version ascending',
        ]);
    }

    /**
     * @param list<array{name: string, version: string, scope: string}> $packages
     * @return list<array{name: string, version: string, scope: string}>
     */
    private function inventoryPackages(array $packages): array
    {
        return array_map(
            static fn(array $package): array => [
                'name' => $package['name'],
                'version' => $package['version'],
                'scope' => $package['scope'],
            ],
            $packages,
        );
    }

    /**
     * @param list<array{name: string, version: string, scope: string, require: array<string, string>|null}> $packages
     */
    private function requirementGraphFinding(array $packages, string $runtimeState, string $developmentState): AuditFinding
    {
        $edges = [];

        foreach ($packages as $package) {
            if ($package['require'] === null) {
                continue;
            }

            foreach ($package['require'] as $requirement => $constraint) {
                if ($this->isPlatformRequirement($requirement)) {
                    continue;
                }

                $edges[] = [
                    'from' => $package['name'],
                    'from_version' => $package['version'],
                    'scope' => $package['scope'],
                    'to' => $requirement,
                    'constraint' => $constraint,
                ];
            }
        }

        usort(
            $edges,
            static fn(array $left, array $right): int => [$left['scope'], $left['from'], $left['from_version'], $left['to'], $left['constraint']]
                <=> [$right['scope'], $right['from'], $right['from_version'], $right['to'], $right['constraint']],
        );

        $complete = $this->completeFromStates($runtimeState, $developmentState);

        return new AuditFinding('composer_lock.requirement_graph', $complete ? AuditSeverity::Info : AuditSeverity::Warning, 'Locked package requirement graph evidence was inspected.', [
            'state' => $this->combinedState($runtimeState, $developmentState),
            'complete' => $complete,
            'edges' => $edges,
            'sort' => 'scope then from package then from version then target package then constraint ascending',
        ]);
    }

    /**
     * @param list<array{name: string, version: string, scope: string, require: array<string, string>|null}> $packages
     * @param list<array{source: string, scope: string, requirement: string, constraint: string}> $rootRequirements
     */
    private function platformRequirementsFinding(
        array $packages,
        array $rootRequirements,
        string $runtimeState,
        string $developmentState,
        string $rootPlatformState,
        string $rootPlatformDevelopmentState,
    ): AuditFinding {
        $requirements = $rootRequirements;

        foreach ($packages as $package) {
            if ($package['require'] === null) {
                continue;
            }

            foreach ($package['require'] as $requirement => $constraint) {
                if (! $this->isPlatformRequirement($requirement)) {
                    continue;
                }

                $requirements[] = [
                    'source' => 'locked-package',
                    'package' => $package['name'],
                    'package_version' => $package['version'],
                    'scope' => $package['scope'],
                    'requirement' => $requirement,
                    'constraint' => $constraint,
                ];
            }
        }

        usort(
            $requirements,
            static fn(array $left, array $right): int => [
                $left['source'],
                $left['scope'],
                $left['package'] ?? '',
                $left['package_version'] ?? '',
                $left['requirement'],
                $left['constraint'],
            ] <=> [
                $right['source'],
                $right['scope'],
                $right['package'] ?? '',
                $right['package_version'] ?? '',
                $right['requirement'],
                $right['constraint'],
            ],
        );

        $complete = $this->completeFromStates($runtimeState, $developmentState, $rootPlatformState, $rootPlatformDevelopmentState);

        return new AuditFinding('composer_lock.platform_requirements', $complete ? AuditSeverity::Info : AuditSeverity::Warning, 'Composer lockfile platform requirement evidence was inspected.', [
            'state' => $this->combinedState($runtimeState, $developmentState, $rootPlatformState, $rootPlatformDevelopmentState),
            'complete' => $complete,
            'requirements' => $requirements,
            'sort' => 'source then scope then package then package version then requirement then constraint ascending',
        ]);
    }

    /**
     * @param list<array{name: string, version: string, scope: string, type: string|null}> $packages
     */
    private function composerPluginsFinding(array $packages, string $runtimeState, string $developmentState): ?AuditFinding
    {
        $plugins = [];

        foreach ($packages as $package) {
            if ($package['type'] === 'composer-plugin') {
                $plugins[] = ['name' => $package['name'], 'version' => $package['version'], 'scope' => $package['scope']];
            }
        }

        if ($plugins === [] && $this->completeFromStates($runtimeState, $developmentState)) {
            return null;
        }

        $this->sortPackageEvidence($plugins);
        $complete = $this->completeFromStates($runtimeState, $developmentState);

        return new AuditFinding('composer_lock.composer_plugins', AuditSeverity::Warning, 'Composer plugin package metadata was inspected.', [
            'state' => $this->combinedState($runtimeState, $developmentState),
            'complete' => $complete,
            'plugins' => $plugins,
            'sort' => 'scope then package name then version ascending',
        ]);
    }

    /**
     * @param list<array{name: string, version: string, scope: string, abandoned: bool|string|null, abandoned_malformed: bool}> $packages
     */
    private function abandonedPackagesFinding(array $packages, string $runtimeState, string $developmentState): ?AuditFinding
    {
        $abandoned = [];
        $hasMalformed = false;

        foreach ($packages as $package) {
            if ($package['abandoned_malformed']) {
                $hasMalformed = true;
                continue;
            }

            if ($package['abandoned'] === null || $package['abandoned'] === false || $package['abandoned'] === '') {
                continue;
            }

            $abandoned[] = [
                'name' => $package['name'],
                'version' => $package['version'],
                'scope' => $package['scope'],
                'abandoned' => $package['abandoned'],
            ];
        }

        if ($abandoned === [] && ! $hasMalformed && $this->completeFromStates($runtimeState, $developmentState)) {
            return null;
        }

        usort(
            $abandoned,
            fn(array $left, array $right): int => [
                $left['scope'],
                $left['name'],
                $left['version'],
                $this->abandonedSortKind($left['abandoned']),
                $this->abandonedSortValue($left['abandoned']),
            ] <=> [
                $right['scope'],
                $right['name'],
                $right['version'],
                $this->abandonedSortKind($right['abandoned']),
                $this->abandonedSortValue($right['abandoned']),
            ],
        );
        $complete = $this->completeFromStates($runtimeState, $developmentState) && ! $hasMalformed;
        $state = $this->combinedState($runtimeState, $developmentState);

        return new AuditFinding('composer_lock.abandoned_packages', AuditSeverity::Warning, 'Lockfile-declared abandoned package metadata was inspected.', [
            'state' => $state,
            'complete' => $complete,
            'packages' => $abandoned,
            'sort' => 'scope then package name then version then abandoned value kind then abandoned value ascending',
        ]);
    }

    private function abandonedSortKind(bool|string $value): string
    {
        return is_bool($value) ? 'boolean' : 'string';
    }

    private function abandonedSortValue(bool|string $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : $value;
    }

    /**
     * @return array{requirements: array<string, string>, issues: list<array<string, string>>}
     */
    private function requirementEntries(\stdClass $requirements, string $section, string $package): array
    {
        $entries = [];
        $issues = [];

        foreach (get_object_vars($requirements) as $requirement => $constraint) {
            if (is_string($constraint)) {
                $entries[$requirement] = $constraint;
                continue;
            }

            $issues[] = [
                'section' => $section,
                'package' => $package,
                'field' => 'require',
                'requirement' => $requirement,
                'reason' => 'constraint must be a string',
                'actual' => $this->typeOf($constraint),
            ];
        }

        ksort($entries, SORT_STRING);

        return ['requirements' => $entries, 'issues' => $issues];
    }

    private function isPlatformRequirement(string $requirement): bool
    {
        return $requirement === 'php'
            || str_starts_with($requirement, 'php-')
            || str_starts_with($requirement, 'ext-')
            || str_starts_with($requirement, 'lib-')
            || $requirement === 'composer'
            || $requirement === 'composer-plugin-api'
            || $requirement === 'composer-runtime-api';
    }

    private function completeFromStates(string ...$states): bool
    {
        foreach ($states as $state) {
            if ($state !== 'valid' && $state !== 'absent') {
                return false;
            }
        }

        return true;
    }

    private function combinedState(string ...$states): string
    {
        if (in_array('unknown', $states, true)) {
            return 'unknown';
        }

        if (in_array('partial', $states, true)) {
            return 'partial';
        }

        if (in_array('valid', $states, true)) {
            return 'valid';
        }

        return 'absent';
    }

    /**
     * @param list<array{name: string, version: string, scope: string, require: array<string, string>|null, type: string|null, abandoned: bool|string|null, abandoned_malformed: bool}> $packages
     */
    private function sortParsedPackages(array &$packages): void
    {
        usort(
            $packages,
            static fn(array $left, array $right): int => [$left['scope'], $left['name'], $left['version']]
                <=> [$right['scope'], $right['name'], $right['version']],
        );
    }

    /**
     * @param list<array{name: string, version: string, scope: string}> $packages
     */
    private function sortPackageEvidence(array &$packages): void
    {
        usort(
            $packages,
            static fn(array $left, array $right): int => [$left['scope'], $left['name'], $left['version']]
                <=> [$right['scope'], $right['name'], $right['version']],
        );
    }

    /**
     * @param list<array<string, string>> $issues
     * @return list<array<string, string>>
     */
    private function sortIssues(array $issues): array
    {
        usort(
            $issues,
            static fn(array $left, array $right): int => [
                $left['section'] ?? '',
                $left['package'] ?? '',
                $left['field'] ?? '',
                $left['requirement'] ?? '',
                $left['reason'] ?? '',
                $left['actual'] ?? '',
            ] <=> [
                $right['section'] ?? '',
                $right['package'] ?? '',
                $right['field'] ?? '',
                $right['requirement'] ?? '',
                $right['reason'] ?? '',
                $right['actual'] ?? '',
            ],
        );

        return $issues;
    }

    private function typeOf(mixed $value): string
    {
        if (is_array($value)) {
            return array_is_list($value) ? 'list' : 'array';
        }

        return match (get_debug_type($value)) {
            'int' => 'integer',
            'bool' => 'boolean',
            default => get_debug_type($value),
        };
    }
}
