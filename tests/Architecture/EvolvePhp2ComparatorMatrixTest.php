<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2ComparatorMatrixTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testComparatorMatrixFileExists(): void
    {
        $matrixPath = $this->projectPath('benchmarks/comparators/matrix.json');
        $this->assertFileExists($matrixPath, 'Comparator matrix must exist at benchmarks/comparators/matrix.json');
    }

    public function testComparatorMatrixIsValidJson(): void
    {
        $matrixPath = $this->projectPath('benchmarks/comparators/matrix.json');
        $content = file_get_contents($matrixPath);
        $data = json_decode($content, associative: true);

        $this->assertIsArray($data, 'Matrix file must contain valid JSON');
        $this->assertArrayHasKey('schema_version', $data);
        $this->assertArrayHasKey('comparators', $data);
        $this->assertArrayHasKey('common_scenarios', $data);
    }

    public function testComparatorMatrixHasFiveFrameworks(): void
    {
        $matrix = $this->readJsonFile('benchmarks/comparators/matrix.json');
        $comparators = $matrix['comparators'] ?? [];

        $this->assertCount(5, $comparators, 'Matrix must include EvolvePHP baseline + 4 frameworks');
        $ids = array_column($comparators, 'id');
        $this->assertContains('evolvephp', $ids);
        $this->assertContains('laravel', $ids);
        $this->assertContains('symfony', $ids);
        $this->assertContains('slim', $ids);
        $this->assertContains('phalcon', $ids);
    }

    public function testComparatorMatrixEnforcesStableOrdering(): void
    {
        $matrix1 = $this->readJsonFile('benchmarks/comparators/matrix.json');
        $matrix2 = $this->readJsonFile('benchmarks/comparators/matrix.json');

        $ids1 = array_column($matrix1['comparators'], 'id');
        $ids2 = array_column($matrix2['comparators'], 'id');

        $this->assertSame($ids1, $ids2, 'Comparator ordering must be stable across loads');
    }

    public function testAllComparatorFixtureDirectoriesExist(): void
    {
        $matrix = $this->readJsonFile('benchmarks/comparators/matrix.json');
        $comparators = $matrix['comparators'] ?? [];
        $comparatorBasePath = $this->projectPath('benchmarks/comparators');

        foreach ($comparators as $comparator) {
            $id = $comparator['id'] ?? 'unknown';
            $fixturePath = $comparatorBasePath . DIRECTORY_SEPARATOR . trim($comparator['fixture_path'] ?? '', '/\\');
            $this->assertDirectoryExists(
                $fixturePath,
                "Fixture directory must exist for comparator '{$id}' at {$fixturePath}"
            );

            // Each fixture must have a composer.json
            $composerPath = $fixturePath . DIRECTORY_SEPARATOR . 'composer.json';
            $this->assertFileExists(
                $composerPath,
                "Fixture must have composer.json at {$composerPath}"
            );
        }
    }

    public function testCommonScenariosAreDefinedAndStable(): void
    {
        $matrix = $this->readJsonFile('benchmarks/comparators/matrix.json');
        $commonScenarios = $matrix['common_scenarios'] ?? [];

        $expectedScenarios = [
            'application_boot',
            'http_static',
            'http_parameterized',
            'http_middleware',
            'http_not_found',
            'http_repeated_warm',
        ];

        foreach ($expectedScenarios as $scenario) {
            $this->assertArrayHasKey($scenario, $commonScenarios, "Scenario '{$scenario}' must be defined");
        }
    }

    public function testComparatorScenariosReferenceOnlyCommonScenarios(): void
    {
        $matrix = $this->readJsonFile('benchmarks/comparators/matrix.json');
        $commonScenarios = array_keys($matrix['common_scenarios'] ?? []);
        $comparators = $matrix['comparators'] ?? [];

        foreach ($comparators as $comparator) {
            $id = $comparator['id'] ?? 'unknown';
            $scenarios = $comparator['scenarios'] ?? [];
            foreach ($scenarios as $scenario) {
                $this->assertContains(
                    $scenario,
                    $commonScenarios,
                    "Comparator '{$id}' references unknown scenario '{$scenario}'"
                );
            }
        }
    }

    public function testPhalconComparatorIsMarkedConditionalAvailability(): void
    {
        $matrix = $this->readJsonFile('benchmarks/comparators/matrix.json');
        $comparators = $matrix['comparators'] ?? [];
        $phalcon = null;

        foreach ($comparators as $comp) {
            if (($comp['id'] ?? null) === 'phalcon') {
                $phalcon = $comp;
                break;
            }
        }

        $this->assertNotNull($phalcon, 'Phalcon comparator must be defined');
        $this->assertSame('conditional', $phalcon['availability'] ?? 'always', 'Phalcon availability must be conditional');
        $this->assertSame('compiled-extension', $phalcon['implementation_model'] ?? 'pure-php', 'Phalcon implementation model must be marked as compiled-extension');
        $this->assertArrayHasKey('availability_condition', $phalcon, 'Phalcon must document its availability condition');
    }

    public function testComparatorMetadataIncludesVersionAndFrameworkInfo(): void
    {
        $matrix = $this->readJsonFile('benchmarks/comparators/matrix.json');
        $comparators = $matrix['comparators'] ?? [];

        foreach ($comparators as $comparator) {
            $id = $comparator['id'] ?? 'unknown';
            $this->assertIsString($comparator['framework_version'] ?? null, "Comparator '{$id}' must have framework_version");
            $this->assertNotEmpty($comparator['framework_version'] ?? '', "Comparator '{$id}' framework_version must not be empty");
            $this->assertIsString($comparator['implementation_model'] ?? null, "Comparator '{$id}' must have implementation_model");
            $this->assertIsString($comparator['availability'] ?? null, "Comparator '{$id}' must have availability");
        }
    }

    public function testComparatorDependenciesStayOutsideProductionPackageManifests(): void
    {
        $benchmarkPackageNames = [
            'evolvephp/benchmarks',
            'evolvephp/benchmark-evolvephp',
            'evolvephp/benchmark-laravel',
            'evolvephp/benchmark-symfony',
            'evolvephp/benchmark-slim',
            'evolvephp/benchmark-phalcon',
            'phpbench/phpbench',
        ];

        foreach (glob($this->projectPath('packages/*/composer.json')) ?: [] as $manifestPath) {
            $manifest = $this->readJsonFile($this->relativeProjectPath($manifestPath));
            $requires = array_merge($manifest['require'] ?? [], $manifest['require-dev'] ?? []);

            foreach ($benchmarkPackageNames as $packageName) {
                $this->assertArrayNotHasKey(
                    $packageName,
                    $requires,
                    "Production package manifest {$manifestPath} must not depend on benchmark tooling"
                );
            }
        }
    }

    public function testApplicationSkeletonDoesNotRequireBenchmarkOrComparatorTooling(): void
    {
        $manifest = $this->readJsonFile('skeleton/composer.json');
        $requires = array_merge($manifest['require'] ?? [], $manifest['require-dev'] ?? []);

        foreach (array_keys($requires) as $packageName) {
            $this->assertFalse(str_starts_with($packageName, 'evolvephp/benchmark-'));
            $this->assertNotSame('evolvephp/benchmarks', $packageName);
            $this->assertNotSame('phpbench/phpbench', $packageName);
        }
    }

    public function testComparatorRootsRemainInsideBenchmarkBoundary(): void
    {
        $matrix = $this->readJsonFile('benchmarks/comparators/matrix.json');
        $benchmarkBoundary = realpath($this->projectPath('benchmarks/comparators'));
        $this->assertIsString($benchmarkBoundary);

        foreach ($matrix['comparators'] ?? [] as $comparator) {
            $fixturePath = realpath($this->projectPath('benchmarks/comparators/' . trim((string) $comparator['fixture_path'], '/\\')));
            $lockPath = realpath($this->projectPath('benchmarks/comparators/' . trim((string) $comparator['lock_path'], '/\\')));

            $this->assertIsString($fixturePath, "Comparator {$comparator['id']} fixture path must resolve");
            $this->assertIsString($lockPath, "Comparator {$comparator['id']} lockfile path must resolve");
            $this->assertStringStartsWith($benchmarkBoundary, $fixturePath);
            $this->assertStringStartsWith($benchmarkBoundary, $lockPath);
        }
    }

    public function testRootProductionDependenciesWereNotExpandedForComparators(): void
    {
        $manifest = $this->readJsonFile('composer.json');
        $runtimeRequires = $manifest['require'] ?? [];

        foreach (array_keys($runtimeRequires) as $packageName) {
            $this->assertFalse(str_starts_with($packageName, 'evolvephp/benchmark-'));
            $this->assertNotSame('evolvephp/benchmarks', $packageName);
            $this->assertNotSame('phpbench/phpbench', $packageName);
        }
    }

    private function projectPath(string $path): string
    {
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function readJsonFile(string $path): mixed
    {
        $fullPath = $this->projectPath($path);
        $this->assertFileExists($fullPath);

        return json_decode(file_get_contents($fullPath), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    private function relativeProjectPath(string $path): string
    {
        return str_replace('\\', '/', substr($path, strlen($this->root) + 1));
    }
}
