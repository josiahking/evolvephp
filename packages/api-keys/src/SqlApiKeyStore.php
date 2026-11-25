<?php

declare(strict_types=1);

namespace Evolve\ApiKeys;

use DateTimeImmutable;
use DateTimeZone;
use Evolve\Database\Contracts\DatabaseConnection;
use Evolve\Database\Contracts\DatabaseStatement;
use RuntimeException;

/** Database commits define visibility; verification performs a fresh read. */
final readonly class SqlApiKeyStore implements ApiKeyStore
{
    public function __construct(private DatabaseConnection $connection) {}

    public function insert(ApiKeyRecord $record): void
    {
        $statement = new DatabaseStatement(
            'INSERT INTO evolve_api_keys (identifier, digest, principal, tenant, scopes_json, expires_at, revoked_at) '
            . 'VALUES (:id, :digest, :principal, :tenant, :scopes, :expires, NULL)',
            ['id' => $record->identifier(), 'digest' => $record->digest(), 'principal' => $record->principal(),
                'tenant' => $record->tenant(), 'scopes' => json_encode($record->scopes(), JSON_THROW_ON_ERROR),
                'expires' => self::timestamp($record->expiresAt())],
            'api-keys.insert',
        );
        $this->connection->transaction(static function (DatabaseConnection $db) use ($statement): void {
            if ($db->execute($statement) !== 1) {
                throw new RuntimeException('API key write failed.');
            }
        });
    }

    public function find(string $identifier, string $principal, string $tenant): ?ApiKeyRecord
    {
        $rows = $this->connection->query(new DatabaseStatement(
            'SELECT identifier, digest, principal, tenant, scopes_json, expires_at, revoked_at '
            . 'FROM evolve_api_keys WHERE identifier = :id AND principal = :principal AND tenant = :tenant',
            ['id' => $identifier, 'principal' => $principal, 'tenant' => $tenant],
            'api-keys.find',
        ));
        $found = null;
        foreach ($rows as $row) {
            if ($found !== null || !is_array($row) || ($row['identifier'] ?? null) !== $identifier
                || ($row['principal'] ?? null) !== $principal || ($row['tenant'] ?? null) !== $tenant) {
                throw new RuntimeException('API key storage integrity failure.');
            }
            try {
                foreach (['digest', 'scopes_json', 'expires_at', 'revoked_at'] as $field) {
                    if (!array_key_exists($field, $row)) {
                        throw new RuntimeException('API key storage integrity failure.');
                    }
                }
                if (!is_string($row['digest']) || !is_string($row['scopes_json'])) {
                    throw new RuntimeException('API key storage integrity failure.');
                }
                $scopes = json_decode($row['scopes_json'], true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($scopes) || !array_is_list($scopes)) {
                    throw new RuntimeException('API key storage integrity failure.');
                }
                $found = new ApiKeyRecord(
                    $identifier,
                    $row['digest'],
                    $principal,
                    $tenant,
                    $scopes,
                    self::parseTimestamp($row['expires_at']),
                    $row['revoked_at'] === null ? null : self::parseTimestamp($row['revoked_at']),
                );
            } catch (\Throwable) {
                throw new RuntimeException('API key storage integrity failure.');
            }
        }
        return $found;
    }

    public function revoke(string $identifier, string $principal, string $tenant, DateTimeImmutable $now): bool
    {
        $statement = new DatabaseStatement(
            'UPDATE evolve_api_keys SET revoked_at = :now WHERE identifier = :id '
            . 'AND principal = :principal AND tenant = :tenant AND revoked_at IS NULL',
            ['now' => self::timestamp($now), 'id' => $identifier, 'principal' => $principal, 'tenant' => $tenant],
            'api-keys.revoke',
        );
        return $this->connection->transaction(static function (DatabaseConnection $db) use ($statement): bool {
            $changed = $db->execute($statement);
            if ($changed > 1) {
                throw new RuntimeException('API key storage integrity failure.');
            }
            return $changed === 1;
        });
    }

    private static function timestamp(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function parseTimestamp(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('API key storage integrity failure.');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $parsed->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            throw new RuntimeException('API key storage integrity failure.');
        }
        return $parsed;
    }
}
