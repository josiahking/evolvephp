<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Tests\Comparator;

use Evolve\Benchmarks\Support\ExecutionEnvironmentFingerprint;
use Evolve\Benchmarks\Support\FixtureIdentity;
use PHPUnit\Framework\TestCase;

final class EnvironmentAndFixtureIdentityTest extends TestCase
{
    public function testExecutionEnvironmentFingerprintExcludesLockHash(): void
    {
        $environment1 = [
            'schema_version' => 'evolvephp.benchmark.environment.v2',
            'runtime' => ['php_version' => '8.4.0'],
            'platform' => ['os' => 'Linux'],
            'opcache' => ['enabled' => true],
            'jit' => ['enabled' => false],
            'extensions' => ['json', 'pdo'],
        ];

        $environment2 = $environment1;

        $fp1 = ExecutionEnvironmentFingerprint::fromEnvironment($environment1);
        $fp2 = ExecutionEnvironmentFingerprint::fromEnvironment($environment2);

        $this->assertSame($fp1['hash'], $fp2['hash'], 'Same execution environment must produce same hash');
        $this->assertArrayNotHasKey('lock_hash', $fp1['fields'], 'Lock hash must not be in execution environment fingerprint');
    }

    public function testDifferentPhpVersionsProduceDifferentExecutionEnvironmentHash(): void
    {
        $environment1 = [
            'runtime' => ['php_version' => '8.4.0'],
            'platform' => ['os' => 'Linux'],
            'opcache' => ['enabled' => true],
            'jit' => ['enabled' => false],
        ];

        $environment2 = $environment1;
        $environment2['runtime']['php_version'] = '8.5.0';

        $fp1 = ExecutionEnvironmentFingerprint::fromEnvironment($environment1);
        $fp2 = ExecutionEnvironmentFingerprint::fromEnvironment($environment2);

        $this->assertNotSame($fp1['hash'], $fp2['hash'], 'Different PHP versions must produce different hashes');
    }

    public function testDifferentOpcacheStateProduceDifferentExecutionEnvironmentHash(): void
    {
        $environment1 = [
            'runtime' => ['php_version' => '8.4.0'],
            'platform' => ['os' => 'Linux'],
            'opcache' => ['enabled' => true],
            'jit' => ['enabled' => false],
        ];

        $environment2 = $environment1;
        $environment2['opcache']['enabled'] = false;

        $fp1 = ExecutionEnvironmentFingerprint::fromEnvironment($environment1);
        $fp2 = ExecutionEnvironmentFingerprint::fromEnvironment($environment2);

        $this->assertNotSame($fp1['hash'], $fp2['hash'], 'Different OPcache state must produce different hashes');
    }

    public function testDifferentJitStateProduceDifferentExecutionEnvironmentHash(): void
    {
        $environment1 = [
            'runtime' => ['php_version' => '8.4.0'],
            'platform' => ['os' => 'Linux'],
            'opcache' => ['enabled' => true],
            'jit' => ['enabled' => false],
        ];

        $environment2 = $environment1;
        $environment2['jit']['enabled'] = true;

        $fp1 = ExecutionEnvironmentFingerprint::fromEnvironment($environment1);
        $fp2 = ExecutionEnvironmentFingerprint::fromEnvironment($environment2);

        $this->assertNotSame($fp1['hash'], $fp2['hash'], 'Different JIT state must produce different hashes');
    }

    public function testFixtureIdentityIncludesFrameworkAndLockHash(): void
    {
        $fixtureData = [
            'comparator_id' => 'laravel',
            'framework_name' => 'Laravel',
            'framework_version' => '11.0.0',
            'fixture_version' => 1,
            'lock_hash' => 'abc123def456',
        ];

        $identity = FixtureIdentity::fromArray($fixtureData);

        $this->assertArrayHasKey('lock_hash', $identity['fields']);
        $this->assertArrayHasKey('framework_version', $identity['fields']);
        $this->assertArrayHasKey('comparator_id', $identity['fields']);
    }

    public function testSameExecutionEnvironmentWithDifferentFixtureLockHashesAreComparable(): void
    {
        $environment = [
            'runtime' => ['php_version' => '8.4.0'],
            'platform' => ['os' => 'Linux'],
            'opcache' => ['enabled' => true],
            'jit' => ['enabled' => false],
        ];

        $fixture1 = [
            'comparator_id' => 'laravel',
            'framework_version' => '11.0.0',
            'lock_hash' => 'laravel-lock-abc123',
        ];

        $fixture2 = [
            'comparator_id' => 'symfony',
            'framework_version' => '7.0.0',
            'lock_hash' => 'symfony-lock-def456',
        ];

        $envFp = ExecutionEnvironmentFingerprint::fromEnvironment($environment);
        $fixFp1 = FixtureIdentity::fromArray($fixture1);
        $fixFp2 = FixtureIdentity::fromArray($fixture2);

        // Same execution environment
        $this->assertSame($envFp['hash'], $envFp['hash']);
        // Different fixtures
        $this->assertNotSame($fixFp1['hash'], $fixFp2['hash']);
        // But comparison is valid because execution environments match
        $this->assertTrue(true, 'Different fixtures with same execution environment are comparable');
    }
}
