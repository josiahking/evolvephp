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
final readonly class ComposerProjectInspector implements AuditInspector
{
    /**
     * @return list<AuditFinding>
     */
    public function inspect(string $projectRoot): array
    {
        $composerPath = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer.json';

        if (! is_file($composerPath) || ! is_readable($composerPath)) {
            return [
                new AuditFinding('composer_json.unavailable', AuditSeverity::Risk, 'composer.json is missing or unreadable.', [
                    'path' => 'composer.json',
                    'reason' => 'missing or unreadable',
                ]),
            ];
        }

        $content = file_get_contents($composerPath);

        if ($content === false) {
            return [
                new AuditFinding('composer_json.unavailable', AuditSeverity::Risk, 'composer.json is missing or unreadable.', [
                    'path' => 'composer.json',
                    'reason' => 'missing or unreadable',
                ]),
            ];
        }

        try {
            $root = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return [
                new AuditFinding('composer_json.invalid_json', AuditSeverity::Risk, 'composer.json is not valid JSON.', [
                    'path' => 'composer.json',
                    'error' => $exception->getMessage(),
                ]),
            ];
        }

        if (! $root instanceof \stdClass) {
            return [
                new AuditFinding('composer_json.root_not_object', AuditSeverity::Risk, 'composer.json root must be a JSON object.', [
                    'path' => 'composer.json',
                    'actual' => $this->typeOf($root),
                ]),
            ];
        }

        $require = $this->dependencyMap($root, 'require');
        $requireDev = $this->dependencyMap($root, 'require-dev');
        $autoload = $this->autoloadMap($root, 'autoload');
        $autoloadDev = $this->autoloadMap($root, 'autoload-dev');
        $findings = [
            new AuditFinding('composer_json.present', AuditSeverity::Info, 'Root composer.json was found.', [
                'path' => 'composer.json',
            ]),
        ];

        foreach ($require['malformed'] ?? [] as $malformedFinding) {
            $findings[] = $malformedFinding;
        }

        foreach ($requireDev['malformed'] ?? [] as $malformedFinding) {
            $findings[] = $malformedFinding;
        }

        $autoloadMalformed = array_merge($autoload['malformed'] ?? [], $autoloadDev['malformed'] ?? []);

        if ($autoloadMalformed !== []) {
            $findings[] = new AuditFinding('composer.autoload.malformed', AuditSeverity::Risk, 'Composer autoload metadata is malformed.', [
                'fields' => $autoloadMalformed,
            ]);
        }

        $phpConstraintFinding = $this->phpConstraintFinding($require['php']);

        if ($phpConstraintFinding !== null) {
            $findings[] = $phpConstraintFinding;
        }

        $findings[] = $this->runtimeDependenciesFinding($require);
        $findings[] = $this->developmentDependenciesFinding($requireDev);
        $findings[] = $this->frameworksFinding($require, $requireDev);

        if ($autoload['state'] !== 'absent' || $autoloadDev['state'] !== 'absent') {
            $findings[] = $this->autoloadFinding($autoload, $autoloadDev);
            $findings[] = $this->autoloadSignalsFinding($autoload, $autoloadDev);
        }

        $platformFinding = $this->platformPhpFinding($root);

        if ($platformFinding !== null) {
            $findings[] = $platformFinding;
        }

        return $findings;
    }

    /**
     * @return array{state: 'absent'|'valid'|'partial'|'unknown', complete: bool, signal_complete: bool, psr-4: ?list<array{prefix: string, paths: ?list<string>, state: 'valid'|'partial'|'malformed'}>, psr-0: ?list<array{prefix: string, paths: ?list<string>, state: 'valid'|'partial'|'malformed'}>, classmap: ?list<string>, files: ?list<string>, malformed?: list<array{field: string, actual: string}>}
     */
    private function autoloadMap(\stdClass $root, string $field): array
    {
        if (! property_exists($root, $field)) {
            return [
                'state' => 'absent',
                'complete' => true,
                'signal_complete' => true,
                'psr-4' => [],
                'psr-0' => [],
                'classmap' => [],
                'files' => [],
            ];
        }

        $value = $root->{$field};

        if (! $value instanceof \stdClass) {
            return [
                'state' => 'unknown',
                'complete' => false,
                'signal_complete' => $field !== 'autoload' ? true : false,
                'psr-4' => null,
                'psr-0' => null,
                'classmap' => null,
                'files' => null,
                'malformed' => [['field' => $field, 'actual' => $this->typeOf($value)]],
            ];
        }

        $malformed = [];
        $psr4 = $this->psrAutoloadEvidence($value, $field, 'psr-4', $malformed);
        $psr0 = $this->psrAutoloadEvidence($value, $field, 'psr-0', $malformed);
        $classmap = $this->orderedPathListEvidence($value, $field, 'classmap', $malformed);
        $files = $this->orderedPathListEvidence($value, $field, 'files', $malformed);
        $state = $malformed === [] ? 'valid' : 'partial';
        $signalComplete = $field !== 'autoload' || $this->autoloadSignalEvidenceComplete($field, $malformed);

        $result = [
            'state' => $state,
            'complete' => $malformed === [],
            'signal_complete' => $signalComplete,
            'psr-4' => $psr4,
            'psr-0' => $psr0,
            'classmap' => $classmap,
            'files' => $files,
        ];

        if ($malformed !== []) {
            $result['malformed'] = $malformed;
        }

        return $result;
    }

    /**
     * @param list<array{field: string, actual: string}> $malformed
     * @return list<array{prefix: string, paths: ?list<string>, state: 'valid'|'partial'|'malformed'}>
     */
    private function psrAutoloadEvidence(\stdClass $autoload, string $field, string $type, array &$malformed): array
    {
        if (! property_exists($autoload, $type)) {
            return [];
        }

        $section = $autoload->{$type};

        if (! $section instanceof \stdClass) {
            $malformed[] = ['field' => $field . '.' . $type, 'actual' => $this->typeOf($section)];

            return [];
        }

        $entries = get_object_vars($section);
        ksort($entries, SORT_STRING);
        $evidence = [];

        foreach ($entries as $prefix => $paths) {
            $pathList = $this->normalizePsrPathList($paths, $field . '.' . $type . '.' . $prefix, $malformed);

            if ($pathList === null) {
                $malformed[] = [
                    'field' => $field . '.' . $type . '.' . $prefix,
                    'actual' => is_array($paths) && array_is_list($paths) ? 'list with non-string paths' : $this->typeOf($paths),
                ];
                $evidence[] = ['prefix' => $prefix, 'paths' => null, 'state' => 'malformed'];

                continue;
            }

            $evidence[] = ['prefix' => $prefix, 'paths' => $pathList['paths'], 'state' => $pathList['complete'] ? 'valid' : 'partial'];
        }

        return $evidence;
    }

    /**
     * @param list<array{field: string, actual: string}> $malformed
     * @return ?array{paths: list<string>, complete: bool}
     */
    private function normalizePsrPathList(mixed $paths, string $field, array &$malformed): ?array
    {
        if (is_string($paths)) {
            return ['paths' => [$paths], 'complete' => true];
        }

        if (! is_array($paths) || ! array_is_list($paths)) {
            return null;
        }

        $evidence = [];
        $complete = true;

        foreach ($paths as $index => $path) {
            if (! is_string($path)) {
                $malformed[] = ['field' => $field . '.' . $index, 'actual' => $this->typeOf($path)];
                $complete = false;

                continue;
            }

            $evidence[] = $path;
        }

        return ['paths' => $evidence, 'complete' => $complete];
    }

    /**
     * @param list<array{field: string, actual: string}> $malformed
     * @return list<string>|null
     */
    private function orderedPathListEvidence(\stdClass $autoload, string $field, string $type, array &$malformed): ?array
    {
        if (! property_exists($autoload, $type)) {
            return [];
        }

        $paths = $autoload->{$type};

        if (! is_array($paths) || ! array_is_list($paths)) {
            $malformed[] = ['field' => $field . '.' . $type, 'actual' => $this->typeOf($paths)];

            return null;
        }

        $evidence = [];

        foreach ($paths as $index => $path) {
            if (! is_string($path)) {
                $malformed[] = ['field' => $field . '.' . $type . '.' . $index, 'actual' => $this->typeOf($path)];

                continue;
            }

            $evidence[] = $path;
        }

        return $evidence;
    }

    /**
     * @param array{state: 'absent'|'valid'|'partial'|'unknown', complete: bool, psr-4: ?list<array{prefix: string, paths: ?list<string>, state: 'valid'|'partial'|'malformed'}>, psr-0: ?list<array{prefix: string, paths: ?list<string>, state: 'valid'|'partial'|'malformed'}>, classmap: ?list<string>, files: ?list<string>} $runtime
     * @param array{state: 'absent'|'valid'|'partial'|'unknown', complete: bool, psr-4: ?list<array{prefix: string, paths: ?list<string>, state: 'valid'|'partial'|'malformed'}>, psr-0: ?list<array{prefix: string, paths: ?list<string>, state: 'valid'|'partial'|'malformed'}>, classmap: ?list<string>, files: ?list<string>} $development
     */
    private function autoloadFinding(array $runtime, array $development): AuditFinding
    {
        $complete = $runtime['complete'] && $development['complete'];

        return new AuditFinding('composer.autoload', $complete ? AuditSeverity::Info : AuditSeverity::Warning, 'Composer autoload metadata was inspected.', [
            'complete' => $complete,
            'runtime' => $this->autoloadSectionEvidence($runtime),
            'development' => $this->autoloadSectionEvidence($development),
            'claim' => 'raw composer autoload metadata only; target autoload is not executed',
        ]);
    }

    /**
     * @param array{state: 'absent'|'valid'|'partial'|'unknown', complete: bool, psr-4: ?list<array{prefix: string, paths: ?list<string>, state: 'valid'|'partial'|'malformed'}>, psr-0: ?list<array{prefix: string, paths: ?list<string>, state: 'valid'|'partial'|'malformed'}>, classmap: ?list<string>, files: ?list<string>} $section
     * @return array{state: string, complete: bool, psr-4: ?list<array{prefix: string, paths: ?list<string>, state: string}>, psr-0: ?list<array{prefix: string, paths: ?list<string>, state: string}>, classmap: ?list<string>, files: ?list<string>}
     */
    private function autoloadSectionEvidence(array $section): array
    {
        return [
            'state' => $section['state'],
            'complete' => $section['complete'],
            'psr-4' => $section['psr-4'],
            'psr-0' => $section['psr-0'],
            'classmap' => $section['classmap'],
            'files' => $section['files'],
        ];
    }

    /**
     * @param array{state: 'absent'|'valid'|'partial'|'unknown', signal_complete: bool, psr-4: ?list<array{prefix: string, paths: ?list<string>, state: 'valid'|'partial'|'malformed'}>, files: ?list<string>} $runtime
     * @param array{state: 'absent'|'valid'|'partial'|'unknown', complete: bool} $development
     */
    private function autoloadSignalsFinding(array $runtime, array $development): AuditFinding
    {
        $candidates = [];

        foreach ($runtime['psr-4'] ?? [] as $mapping) {
            if ($mapping['state'] !== 'valid' || $mapping['prefix'] === '' || $mapping['paths'] === null) {
                continue;
            }

            $candidates[] = [
                'kind' => 'runtime_psr4_namespace',
                'prefix' => $mapping['prefix'],
                'paths' => $mapping['paths'],
            ];
        }

        $reviewSignals = [];

        foreach ($runtime['files'] ?? [] as $path) {
            $reviewSignals[] = [
                'kind' => 'autoload_file',
                'path' => $path,
                'signal' => 'requires migration review',
            ];
        }

        $hasSignals = $candidates !== [] || $reviewSignals !== [];

        return new AuditFinding('modernization.autoload_signals', $hasSignals ? AuditSeverity::Warning : AuditSeverity::Info, 'Composer autoload modernization review signals were inspected.', [
            'complete' => $runtime['signal_complete'],
            'autoload_state' => [
                'runtime' => $runtime['state'],
                'development' => $development['state'],
            ],
            'candidates' => $candidates,
            'review_signals' => $reviewSignals,
            'claim' => 'structural review signals only; not proof of module or capability boundaries, migration feasibility, Bridge compatibility, or that migration is blocked',
        ]);
    }

    /**
     * @param list<array{field: string, actual: string}> $malformed
     */
    private function autoloadSignalEvidenceComplete(string $field, array $malformed): bool
    {
        foreach ($malformed as $evidence) {
            if (
                str_starts_with($evidence['field'], $field . '.psr-4')
                || str_starts_with($evidence['field'], $field . '.files')
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{state: 'absent'|'valid'|'partial'|'unknown', dependencies: array<string, string>, packages: array<string, array{constraint: string|null, constraint_state: 'valid'|'malformed'}>, php: array{state: 'absent'|'valid'|'malformed'|'unknown', constraint?: string}, malformed?: list<AuditFinding>}
     */
    private function dependencyMap(\stdClass $root, string $field): array
    {
        if (! property_exists($root, $field)) {
            return ['state' => 'absent', 'dependencies' => [], 'packages' => [], 'php' => ['state' => 'absent']];
        }

        $value = $root->{$field};

        if (! $value instanceof \stdClass) {
            return [
                'state' => 'unknown',
                'dependencies' => [],
                'packages' => [],
                'php' => ['state' => $field === 'require' ? 'unknown' : 'absent'],
                'malformed' => [new AuditFinding($this->malformedDependencyIdentifier($field), AuditSeverity::Risk, $field . ' must be a JSON object.', [
                    'field' => $field,
                    'actual' => $this->typeOf($value),
                ])],
            ];
        }

        $dependencies = [];
        $packages = [];
        $malformed = [];
        $php = ['state' => 'absent'];
        $entries = get_object_vars($value);

        ksort($entries, SORT_STRING);

        foreach ($entries as $package => $constraint) {
            if (! is_string($constraint)) {
                $packages[$package] = [
                    'constraint' => null,
                    'constraint_state' => 'malformed',
                ];

                if ($field === 'require' && $package === 'php') {
                    $php = ['state' => 'malformed'];
                    $malformed[] = new AuditFinding('composer.require_php.malformed', AuditSeverity::Risk, 'Root require.php must be a non-empty string when present.', [
                        'field' => 'require.php',
                        'actual' => is_array($constraint) ? 'array' : $this->typeOf($constraint),
                    ]);

                    continue;
                }

                $malformed[] = new AuditFinding($this->malformedDependencyIdentifier($field), AuditSeverity::Risk, $field . ' must contain string package constraints.', [
                    'field' => $field,
                    'actual' => 'object with non-string package constraints',
                ]);

                continue;
            }

            if ($field === 'require' && $package === 'php' && $constraint === '') {
                $packages[$package] = [
                    'constraint' => null,
                    'constraint_state' => 'malformed',
                ];
                $php = ['state' => 'malformed'];
                $malformed[] = new AuditFinding('composer.require_php.malformed', AuditSeverity::Risk, 'Root require.php must be a non-empty string when present.', [
                    'field' => 'require.php',
                    'actual' => 'empty string',
                ]);

                continue;
            }

            if ($field === 'require' && $package === 'php') {
                $php = ['state' => 'valid', 'constraint' => $constraint];
            }

            $dependencies[$package] = $constraint;
            $packages[$package] = [
                'constraint' => $constraint,
                'constraint_state' => 'valid',
            ];
        }

        if ($malformed === []) {
            return ['state' => 'valid', 'dependencies' => $dependencies, 'packages' => $packages, 'php' => $php];
        }

        return ['state' => 'partial', 'dependencies' => $dependencies, 'packages' => $packages, 'php' => $php, 'malformed' => $malformed];
    }

    /**
     * @param array{state: 'absent'|'valid'|'malformed'|'unknown', constraint?: string} $php
     */
    private function phpConstraintFinding(array $php): ?AuditFinding
    {
        if ($php['state'] === 'unknown' || $php['state'] === 'malformed') {
            return null;
        }

        if ($php['state'] === 'absent') {
            return new AuditFinding('composer.php_constraint', AuditSeverity::Info, 'No direct root PHP constraint was found.', [
                'present' => false,
                'constraint' => null,
                'claim' => 'no direct root PHP constraint found',
            ]);
        }

        return new AuditFinding('composer.php_constraint', AuditSeverity::Info, 'Raw root PHP constraint was found.', [
            'present' => true,
            'constraint' => $php['constraint'],
            'claim' => 'raw composer constraint only',
        ]);
    }

    /**
     * @param array{state: 'absent'|'valid'|'partial'|'unknown', dependencies: array<string, string>} $section
     */
    private function runtimeDependenciesFinding(array $section): AuditFinding
    {
        return $this->dependenciesFinding('composer.dependencies.runtime', 'runtime', $section);
    }

    /**
     * @param array{state: 'absent'|'valid'|'partial'|'unknown', dependencies: array<string, string>} $section
     */
    private function developmentDependenciesFinding(array $section): AuditFinding
    {
        return $this->dependenciesFinding('composer.dependencies.development', 'development', $section);
    }

    /**
     * @param array{state: 'absent'|'valid'|'partial'|'unknown', dependencies: array<string, string>} $section
     */
    private function dependenciesFinding(string $identifier, string $scope, array $section): AuditFinding
    {
        $complete = $section['state'] === 'absent' || $section['state'] === 'valid';

        return new AuditFinding($identifier, $complete ? AuditSeverity::Info : AuditSeverity::Warning, 'Direct ' . $scope . ' dependency evidence was inspected.', [
            'scope' => $scope,
            'state' => $section['state'],
            'complete' => $complete,
            'dependencies' => $section['state'] === 'unknown' ? null : $this->dependencyEvidence($section['dependencies']),
            'sort' => 'package name ascending',
        ]);
    }

    /**
     * @param array{state: 'absent'|'valid'|'partial'|'unknown', packages: array<string, array{constraint: string|null, constraint_state: 'valid'|'malformed'}>} $runtime
     * @param array{state: 'absent'|'valid'|'partial'|'unknown', packages: array<string, array{constraint: string|null, constraint_state: 'valid'|'malformed'}>} $development
     */
    private function frameworksFinding(array $runtime, array $development): AuditFinding
    {
        $frameworks = [];

        foreach ([
            'runtime' => $runtime['packages'],
            'development' => $development['packages'],
        ] as $scope => $packages) {
            foreach ($packages as $package => $evidence) {
                $family = $this->frameworkFamily($package);

                if ($family === null) {
                    continue;
                }

                $framework = [
                    'family' => $family,
                    'package' => $package,
                    'scope' => $scope,
                    'constraint' => $evidence['constraint'],
                ];

                if ($evidence['constraint_state'] !== 'valid') {
                    $framework['constraint_state'] = $evidence['constraint_state'];
                }

                $frameworks[] = $framework;
            }
        }

        usort(
            $frameworks,
            static fn(array $left, array $right): int => [$left['family'], $left['package'], $left['scope']]
                <=> [$right['family'], $right['package'], $right['scope']],
        );

        $complete = ($runtime['state'] === 'absent' || $runtime['state'] === 'valid')
            && ($development['state'] === 'absent' || $development['state'] === 'valid');
        $stateEvidence = [
            'runtime' => $runtime['state'],
            'development' => $development['state'],
        ];

        if ($frameworks === [] && $complete) {
            return new AuditFinding('composer.frameworks', AuditSeverity::Info, 'No direct supported framework package evidence was found.', [
                'dependency_state' => $stateEvidence,
                'complete' => true,
                'frameworks' => [],
                'claim' => 'no direct supported framework package evidence found',
            ]);
        }

        if ($frameworks === []) {
            return new AuditFinding('composer.frameworks', AuditSeverity::Warning, 'Framework package evidence is incomplete.', [
                'dependency_state' => $stateEvidence,
                'complete' => false,
                'frameworks' => [],
                'claim' => 'framework evidence incomplete because dependency evidence is partial or unknown',
            ]);
        }

        return new AuditFinding('composer.frameworks', AuditSeverity::Warning, 'Direct framework package evidence was found.', [
            'dependency_state' => $stateEvidence,
            'complete' => $complete,
            'frameworks' => $frameworks,
            'claim' => 'direct composer package evidence only',
        ]);
    }

    private function platformPhpFinding(\stdClass $root): ?AuditFinding
    {
        if (! property_exists($root, 'config')) {
            return null;
        }

        $config = $root->config;

        if (! $config instanceof \stdClass || ! property_exists($config, 'platform')) {
            return null;
        }

        $platform = $config->platform;

        if (! $platform instanceof \stdClass || ! property_exists($platform, 'php')) {
            return null;
        }

        $php = $platform->php;

        if (! is_string($php) || $php === '') {
            return new AuditFinding('composer.config_platform_php.malformed', AuditSeverity::Risk, 'Composer config.platform.php must be a non-empty string when present.', [
                'field' => 'config.platform.php',
                'actual' => $php === '' ? 'empty string' : $this->typeOf($php),
            ]);
        }

        return new AuditFinding('composer.platform_php', AuditSeverity::Warning, 'Composer platform PHP override requires compatibility review.', [
            'present' => true,
            'value' => $php,
            'claim' => 'Composer platform emulation is not proof of the actual runtime PHP version',
        ]);
    }

    /**
     * @param array<string, string> $dependencies
     * @return list<array{name: string, constraint: string}>
     */
    private function dependencyEvidence(array $dependencies): array
    {
        unset($dependencies['php']);
        ksort($dependencies, SORT_STRING);

        $evidence = [];

        foreach ($dependencies as $package => $constraint) {
            $evidence[] = ['name' => $package, 'constraint' => $constraint];
        }

        return $evidence;
    }

    private function frameworkFamily(string $package): ?string
    {
        return match (true) {
            $package === 'laravel/framework' => 'laravel',
            $package === 'symfony/framework-bundle', $package === 'symfony/symfony' => 'symfony',
            $package === 'cakephp/cakephp' => 'cakephp',
            $package === 'yiisoft/yii2' => 'yii',
            str_starts_with($package, 'evolvephp/') => 'evolvephp',
            default => null,
        };
    }

    private function malformedDependencyIdentifier(string $field): string
    {
        return match ($field) {
            'require' => 'composer.require.malformed',
            'require-dev' => 'composer.require_dev.malformed',
            default => throw new \InvalidArgumentException('Unknown dependency field.'),
        };
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
