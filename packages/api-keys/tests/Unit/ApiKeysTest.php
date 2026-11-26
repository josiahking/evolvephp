<?php

declare(strict_types=1);

namespace Evolve\ApiKeys\Tests\Unit;

use DateTimeImmutable;
use Evolve\ApiKeys\ApiKeyAccessPolicy;
use Evolve\ApiKeys\ApiKeyIssueRequest;
use Evolve\ApiKeys\ApiKeys;
use Evolve\ApiKeys\ApiKeysMigrationContributor;
use Evolve\ApiKeys\ApiKeyVerificationRequest;
use Evolve\ApiKeys\Exception\UnauthorizedApiKeyOperation;
use Evolve\ApiKeys\SqlApiKeyStore;
use Evolve\Database\Contracts\DatabaseStatement;
use Evolve\Database\Pdo\PdoDatabaseConnection;
use PDO;
use PHPUnit\Framework\TestCase;

final class ApiKeysTest extends TestCase
{
    /** @return array{ApiKeys, PdoDatabaseConnection} */
    private function service(?ApiKeyAccessPolicy $policy = null): array
    {
        $db = new PdoDatabaseConnection(new PDO('sqlite::memory:'));
        foreach ((new ApiKeysMigrationContributor())->definitions() as $definition) {
            $definition->action()->invoke($db);
        }
        $policy ??= new class implements ApiKeyAccessPolicy {
            public function canIssue(string $actor, string $principal, string $tenant, array $scopes): bool
            {
                return $actor === 'admin';
            }
            public function canRevoke(string $actor, string $principal, string $tenant): bool
            {
                return $actor === 'admin';
            }
        };
        return [new ApiKeys(new SqlApiKeyStore($db), $policy), $db];
    }

    public function testIssueVerifyRevokeAndSecretIsNeverStored(): void
    {
        [$keys, $db] = $this->service();
        $issued = $keys->issue(new ApiKeyIssueRequest('admin', 'user-1', 'tenant-a', ['read'], new DateTimeImmutable('2030-01-01T00:00:00Z')));
        self::assertMatchesRegularExpression('/\Aak1\.[0-9a-f]{32}\.[0-9a-f]{64}\z/D', $issued->credential());
        self::assertSame(32, strlen($issued->identifier()));
        $rows = iterator_to_array($db->query(new DatabaseStatement('SELECT * FROM evolve_api_keys')));
        self::assertCount(1, $rows);
        self::assertStringNotContainsString($issued->credential(), json_encode($rows, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(substr($issued->credential(), -64), json_encode($rows, JSON_THROW_ON_ERROR));
        $request = new ApiKeyVerificationRequest($issued->credential(), 'user-1', 'tenant-a', ['read'], new DateTimeImmutable('2029-01-01T00:00:00Z'));
        self::assertTrue($keys->verify($request)->allowed());
        self::assertFalse($keys->verify(new ApiKeyVerificationRequest('malformed', 'user-1', 'tenant-a', ['read'], new DateTimeImmutable('2029-01-01')))->allowed());
        self::assertFalse($keys->verify(new ApiKeyVerificationRequest('ak1.' . str_repeat('0', 32) . '.' . str_repeat('1', 64), 'user-1', 'tenant-a', ['read'], new DateTimeImmutable('2029-01-01')))->allowed());
        self::assertFalse($keys->verify(new ApiKeyVerificationRequest($issued->credential(), 'user-1', 'tenant-b', ['read'], new DateTimeImmutable('2029-01-01T00:00:00Z')))->allowed());
        self::assertFalse($keys->verify(new ApiKeyVerificationRequest($issued->credential(), 'user-2', 'tenant-a', ['read'], new DateTimeImmutable('2029-01-01T00:00:00Z')))->allowed());
        self::assertFalse($keys->verify(new ApiKeyVerificationRequest($issued->credential(), 'user-1', 'tenant-a', ['write'], new DateTimeImmutable('2029-01-01T00:00:00Z')))->allowed());
        self::assertFalse($keys->verify(new ApiKeyVerificationRequest($issued->credential(), 'user-1', 'tenant-a', ['read'], new DateTimeImmutable('2030-01-01T00:00:00Z')))->allowed());
        $keys->revoke('admin', $issued->identifier(), 'user-1', 'tenant-a');
        self::assertFalse($keys->verify($request)->allowed());
    }

    public function testUnauthorizedIssueAndRevokeAreDenied(): void
    {
        [$keys] = $this->service();
        try {
            $keys->issue(new ApiKeyIssueRequest('outsider', 'user-1', 'tenant-a', ['read'], new DateTimeImmutable('2030-01-01T00:00:00Z')));
            self::fail('Expected issuance denial.');
        } catch (UnauthorizedApiKeyOperation) {
        }
        $issued = $keys->issue(new ApiKeyIssueRequest('admin', 'user-1', 'tenant-a', ['read'], new DateTimeImmutable('2030-01-01T00:00:00Z')));
        $this->expectException(UnauthorizedApiKeyOperation::class);
        $keys->revoke('outsider', $issued->identifier(), 'user-1', 'tenant-a');
    }
}
