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

        $phpConstraintFinding = $this->phpConstraintFinding($require['php']);

        if ($phpConstraintFinding !== null) {
            $findings[] = $phpConstraintFinding;
        }

        $findings[] = $this->runtimeDependenciesFinding($require);
        $findings[] = $this->developmentDependenciesFinding($requireDev);
        $findings[] = $this->frameworksFinding($require, $requireDev);

        $platformFinding = $this->platformPhpFinding($root);

        if ($platformFinding !== null) {
            $findings[] = $platformFinding;
        }

        return $findings;
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
