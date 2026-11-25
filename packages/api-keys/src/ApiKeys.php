<?php

declare(strict_types=1);

namespace Evolve\ApiKeys;

use DateTimeImmutable;
use Evolve\ApiKeys\Exception\InvalidApiKey;
use Evolve\ApiKeys\Exception\UnauthorizedApiKeyOperation;

/** Stateless credential operations; all identity is supplied on each call. */
final readonly class ApiKeys
{
    public function __construct(private ApiKeyStore $store, private ApiKeyAccessPolicy $access) {}

    public function issue(ApiKeyIssueRequest $request): IssuedApiKey
    {
        if (!$this->access->canIssue($request->actor, $request->principal, $request->tenant, $request->scopes)) {
            throw new UnauthorizedApiKeyOperation('API key operation is not authorized.');
        }
        $identifier = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        $this->store->insert(new ApiKeyRecord(
            $identifier,
            hash('sha256', $secret),
            $request->principal,
            $request->tenant,
            $request->scopes,
            $request->expiresAt,
        ));
        return new IssuedApiKey($identifier, 'ak1.' . $identifier . '.' . $secret);
    }

    public function verify(ApiKeyVerificationRequest $request): ApiKeyVerificationResult
    {
        if (preg_match('/\Aak1\.([0-9a-f]{32})\.([0-9a-f]{64})\z/D', $request->credential(), $parts) !== 1) {
            return new ApiKeyVerificationResult(false);
        }
        $record = $this->store->find($parts[1], $request->principal, $request->tenant);
        if ($record === null) {
            return new ApiKeyVerificationResult(false);
        }
        $valid = hash_equals($record->digest(), hash('sha256', $parts[2]));
        $valid = $valid && $record->revokedAt() === null;
        $valid = $valid && $record->expiresAt() > $request->now;
        $valid = $valid && array_diff($request->requiredScopes, $record->scopes()) === [];
        return new ApiKeyVerificationResult($valid);
    }

    public function revoke(string $actor, string $identifier, string $principal, string $tenant): void
    {
        ApiKeyRecord::assertToken($actor);
        ApiKeyRecord::assertToken($identifier);
        ApiKeyRecord::assertToken($principal);
        ApiKeyRecord::assertToken($tenant);
        if (!$this->access->canRevoke($actor, $principal, $tenant)) {
            throw new UnauthorizedApiKeyOperation('API key operation is not authorized.');
        }
        if (!$this->store->revoke($identifier, $principal, $tenant, new DateTimeImmutable('now'))) {
            throw new InvalidApiKey('API key operation failed.');
        }
    }
}
