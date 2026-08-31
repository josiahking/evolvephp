<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Tests\Comparator;

use Evolve\Benchmarks\Comparator\ComparatorMatrix;
use Evolve\Benchmarks\Comparator\ComparatorMatrixException;
use PHPUnit\Framework\TestCase;

final class ComparatorMatrixTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'comparators';
    }

    public function testValidComparatorMatrixLoadsSuccessfully(): void
    {
        $matrixPath = $this->fixtureDir . DIRECTORY_SEPARATOR . 'matrix.json';
        $this->assertFileExists($matrixPath, 'matrix.json must exist at benchmarks/comparators/matrix.json');

        $matrix = ComparatorMatrix::fromJsonFile($matrixPath);
        $comparators = $matrix->comparators();

        $this->assertCount(5, $comparators, 'Matrix must define exactly 5 comparators (EvolvePHP baseline + 4 frameworks)');
        $this->assertArrayHasKey('evolvephp', $comparators);
        $this->assertArrayHasKey('laravel', $comparators);
        $this->assertArrayHasKey('symfony', $comparators);
        $this->assertArrayHasKey('slim', $comparators);
        $this->assertArrayHasKey('phalcon', $comparators);
    }

    public function testComparatorMatrixEnforcesStableOrdering(): void
    {
        $matrixPath = $this->fixtureDir . DIRECTORY_SEPARATOR . 'matrix.json';
        $matrix1 = ComparatorMatrix::fromJsonFile($matrixPath);
        $matrix2 = ComparatorMatrix::fromJsonFile($matrixPath);

        $this->assertSame(
            array_keys($matrix1->comparators()),
            array_keys($matrix2->comparators()),
            'Comparator ordering must be deterministic'
        );
    }

    public function testDuplicateComparatorIdThrowsException(): void
    {
        $invalidMatrix = [
            'schema_version' => 'evolvephp.comparator.matrix.v1',
            'comparators' => [
                [
                    'id' => 'duplicate',
                    'name' => 'Duplicate 1',
                    'framework_version' => '1.0.0',
                    'fixture_path' => 'comparators/dup1',
                    'fixture_bootstrap' => 'comparators/dup1/bootstrap.php',
                    'lock_path' => 'comparators/dup1/composer.lock',
                ],
                [
                    'id' => 'duplicate',
                    'name' => 'Duplicate 2',
                    'framework_version' => '2.0.0',
                    'fixture_path' => 'comparators/dup2',
                    'fixture_bootstrap' => 'comparators/dup2/bootstrap.php',
                    'lock_path' => 'comparators/dup2/composer.lock',
                ],
            ],
        ];

        $this->expectException(ComparatorMatrixException::class);
        ComparatorMatrix::fromArray($invalidMatrix);
    }

    public function testMissingFixtureDirectoryThrowsException(): void
    {
        $invalidMatrix = [
            'schema_version' => 'evolvephp.comparator.matrix.v1',
            'comparators' => [
                [
                    'id' => 'missing-fixture',
                    'name' => 'Missing Fixture Framework',
                    'framework_version' => '1.0.0',
                    'fixture_path' => 'nonexistent/path',
                    'fixture_bootstrap' => 'nonexistent/path/bootstrap.php',
                    'lock_path' => 'nonexistent/path/composer.lock',
                ],
            ],
        ];

        $this->expectException(ComparatorMatrixException::class);
        ComparatorMatrix::fromArray($invalidMatrix, $this->fixtureDir);
    }

    public function testMalformedFrameworkDefinitionThrowsException(): void
    {
        $invalidMatrix = [
            'schema_version' => 'evolvephp.comparator.matrix.v1',
            'comparators' => [
                [
                    'id' => 'incomplete',
                    // Missing required fields: name, framework_version, fixture_path
                ],
            ],
        ];

        $this->expectException(ComparatorMatrixException::class);
        ComparatorMatrix::fromArray($invalidMatrix);
    }

    public function testUnknownScenarioReferenceThrowsException(): void
    {
        $invalidMatrix = $this->minimalMatrix();
        $invalidMatrix['comparators'][0]['scenarios'] = ['not_a_common_scenario'];

        $this->expectException(ComparatorMatrixException::class);
        $this->expectExceptionMessage('Unknown scenario');
        ComparatorMatrix::fromArray($invalidMatrix);
    }

    public function testMatrixIncludesAllRequiredFrameworks(): void
    {
        $matrixPath = $this->fixtureDir . DIRECTORY_SEPARATOR . 'matrix.json';
        $matrix = ComparatorMatrix::fromJsonFile($matrixPath);
        $comparators = $matrix->comparators();

        $requiredFrameworks = ['evolvephp', 'laravel', 'symfony', 'slim', 'phalcon'];
        foreach ($requiredFrameworks as $framework) {
            $this->assertArrayHasKey(
                $framework,
                $comparators,
                "Required framework '{$framework}' must be in matrix"
            );
        }
    }

    public function testEachComparatorHasRequiredMetadata(): void
    {
        $matrixPath = $this->fixtureDir . DIRECTORY_SEPARATOR . 'matrix.json';
        $matrix = ComparatorMatrix::fromJsonFile($matrixPath);

        foreach ($matrix->comparators() as $id => $comparator) {
            $this->assertArrayHasKey('name', $comparator);
            $this->assertArrayHasKey('framework_version', $comparator);
            $this->assertArrayHasKey('fixture_path', $comparator);
            $this->assertArrayHasKey('lock_path', $comparator);
            $this->assertArrayHasKey('scenarios', $comparator);

            $this->assertIsString($comparator['name']);
            $this->assertIsString($comparator['framework_version']);
            $this->assertIsString($comparator['fixture_path']);
            $this->assertIsString($comparator['lock_path']);
            $this->assertIsArray($comparator['scenarios']);
        }
    }

    public function testMatrixReferencesExecutableFixturesAndLockfiles(): void
    {
        $matrixPath = $this->fixtureDir . DIRECTORY_SEPARATOR . 'matrix.json';
        $matrix = ComparatorMatrix::fromJsonFile($matrixPath);

        foreach ($matrix->comparators() as $id => $comparator) {
            $fixturePath = $this->fixtureDir . DIRECTORY_SEPARATOR . trim((string) $comparator['fixture_path'], '/\\');
            $bootstrapPath = $this->fixtureDir . DIRECTORY_SEPARATOR . trim((string) $comparator['fixture_bootstrap'], '/\\');
            $lockPath = $this->fixtureDir . DIRECTORY_SEPARATOR . trim((string) $comparator['lock_path'], '/\\');

            $this->assertDirectoryExists($fixturePath, "Comparator '{$id}' fixture directory must exist");
            $this->assertFileExists($bootstrapPath, "Comparator '{$id}' must expose an executable bootstrap");
            $this->assertFileExists($lockPath, "Comparator '{$id}' must own a Composer lockfile");
        }
    }

    public function testMatrixUsesExactSelectedVersions(): void
    {
        $matrixPath = $this->fixtureDir . DIRECTORY_SEPARATOR . 'matrix.json';
        $matrix = ComparatorMatrix::fromJsonFile($matrixPath);

        foreach ($matrix->comparators() as $id => $comparator) {
            $version = (string) $comparator['framework_version'];

            $this->assertDoesNotMatchRegularExpression('/\blatest\b/i', $version, "Comparator '{$id}' must not use latest wording");
            $this->assertDoesNotMatchRegularExpression('/\.x\b/i', $version, "Comparator '{$id}' must record an exact version");
            $this->assertDoesNotMatchRegularExpression('/\^|~|\*/', $version, "Comparator '{$id}' must not use a Composer constraint as its selected version");
        }
    }

    public function testExternalComparatorPrincipalVersionsAreApprovedExactPins(): void
    {
        $matrixPath = $this->fixtureDir . DIRECTORY_SEPARATOR . 'matrix.json';
        $matrix = ComparatorMatrix::fromJsonFile($matrixPath);

        $expected = [
            'laravel' => ['package' => 'laravel/framework', 'version' => '13.29.0', 'constraint' => '13.29.0'],
            'symfony' => ['package' => 'symfony/http-kernel', 'version' => '8.1.5', 'constraint' => '8.1.5'],
            'slim' => ['package' => 'slim/slim', 'version' => '4.15.2', 'constraint' => '4.15.2'],
            'phalcon' => ['package' => 'ext-phalcon', 'version' => '5.20.3', 'constraint' => 'suggest ext-phalcon 5.20.3'],
        ];

        foreach ($expected as $id => $pin) {
            $comparator = $matrix->comparator($id);
            $this->assertIsArray($comparator);
            $this->assertSame($pin['package'], $comparator['framework_package']);
            $this->assertSame($pin['version'], $comparator['framework_version']);
            $this->assertSame($pin['constraint'], $comparator['composer_constraint']);
        }
    }

    public function testMatrixManifestAndLockfilePrincipalVersionsCannotDrift(): void
    {
        $matrixPath = $this->fixtureDir . DIRECTORY_SEPARATOR . 'matrix.json';
        $matrix = ComparatorMatrix::fromJsonFile($matrixPath);

        foreach (['laravel', 'symfony', 'slim'] as $id) {
            $comparator = $matrix->comparator($id);
            $this->assertIsArray($comparator);

            $fixturePath = $this->fixtureDir . DIRECTORY_SEPARATOR . trim((string) $comparator['fixture_path'], '/\\');
            $manifest = $this->readJsonFile($fixturePath . DIRECTORY_SEPARATOR . 'composer.json');
            $lock = $this->readJsonFile($this->fixtureDir . DIRECTORY_SEPARATOR . trim((string) $comparator['lock_path'], '/\\'));
            $package = (string) $comparator['framework_package'];

            $this->assertSame($comparator['composer_constraint'], $manifest['require'][$package] ?? null);
            $this->assertSame($comparator['framework_version'], ltrim($this->lockedVersion($lock, $package), 'v'));
        }
    }

    public function testMatrixLockHashesMatchLockfiles(): void
    {
        $matrixPath = $this->fixtureDir . DIRECTORY_SEPARATOR . 'matrix.json';
        $matrix = ComparatorMatrix::fromJsonFile($matrixPath);

        foreach ($matrix->comparators() as $id => $comparator) {
            $lockPath = $this->fixtureDir . DIRECTORY_SEPARATOR . trim((string) $comparator['lock_path'], '/\\');

            $this->assertSame(
                strtolower((string) $comparator['lock_sha256']),
                hash_file('sha256', $lockPath),
                "Comparator '{$id}' matrix lock hash must match its lockfile"
            );
        }
    }

    public function testMissingFixtureBootstrapThrowsException(): void
    {
        $invalidMatrix = $this->minimalMatrix();
        $invalidMatrix['comparators'][0]['fixture_bootstrap'] = 'fixture/missing.php';

        $this->expectException(ComparatorMatrixException::class);
        $this->expectExceptionMessage('Fixture bootstrap not found');
        ComparatorMatrix::fromArray($invalidMatrix, $this->fixtureDir);
    }

    public function testMissingLockfileThrowsException(): void
    {
        $invalidMatrix = $this->minimalMatrix();
        $invalidMatrix['comparators'][0]['lock_path'] = 'fixture/missing.lock';

        $this->expectException(ComparatorMatrixException::class);
        $this->expectExceptionMessage('Lockfile not found');
        ComparatorMatrix::fromArray($invalidMatrix, $this->fixtureDir);
    }

    public function testUnsupportedImplementationModelThrowsException(): void
    {
        $invalidMatrix = $this->minimalMatrix();
        $invalidMatrix['comparators'][0]['implementation_model'] = 'shortcut';

        $this->expectException(ComparatorMatrixException::class);
        $this->expectExceptionMessage('Unsupported implementation model');
        ComparatorMatrix::fromArray($invalidMatrix);
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalMatrix(): array
    {
        return [
            'schema_version' => 'evolvephp.comparator.matrix.v1',
            'common_scenarios' => [
                'http_static' => ['id' => 'http_static'],
            ],
            'comparators' => [
                [
                    'id' => 'fixture',
                    'name' => 'Fixture',
                    'framework_package' => 'fixture/framework',
                    'framework_version' => '1.0.0',
                    'composer_constraint' => '^1.0',
                    'fixture_path' => 'evolvephp',
                    'fixture_bootstrap' => 'evolvephp/bootstrap.php',
                    'lock_path' => 'evolvephp/composer.lock',
                    'implementation_model' => 'pure-php',
                    'availability' => 'always',
                    'scenarios' => ['http_static'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonFile(string $path): array
    {
        $this->assertFileExists($path);

        return json_decode(file_get_contents($path), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $lock
     */
    private function lockedVersion(array $lock, string $package): string
    {
        foreach ($lock['packages'] ?? [] as $lockedPackage) {
            if (($lockedPackage['name'] ?? null) === $package) {
                return (string) $lockedPackage['version'];
            }
        }

        $this->fail("Package '{$package}' must exist in comparator lockfile");
    }
}
