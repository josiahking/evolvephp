---
title: API Keys
description: Explicit issuance, tenant-bound verification, and revocation of API credentials.
---

# API Keys

The experimental evolvephp/api-keys package is available as local source for PHP ^8.4. It is not independently published. The host must explicitly include ApiKeysModule in its Module SDK definitions; omitting it leaves the package inert.

Install through a local Composer path repository that includes this package and its first-party dependencies. The package directly requires Contracts, Database Contracts, I18n, Migration, Module, and PSR Container. Run ApiKeysMigrationContributor through the host migration runner before use. Registration and boot never execute SQL. The migration requests database transaction mode; DDL rollback beyond the tested SQLite adapter is unverified, so hosts must check their database before applying it.

## Host registration

The host supplies DatabaseConnection and an implementation of ApiKeyAccessPolicy in its container. The policy decides canIssue() and canRevoke() for each actor, principal and tenant; there is no default grant. Construct an explicit module definition using the existing Module SDK:

```php
use Evolve\ApiKeys\ApiKeysModule;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Module\ModuleDefinition;
use Evolve\Module\ModuleDescriptor;

$definition = new ModuleDefinition(
    new ModuleDescriptor(new ComponentIdentifier('evolvephp/api-keys'), 'API Keys', 2),
    ApiKeysModule::class,
);
// Include $definition in the application's explicit module definitions.
```

The host resolves the execution-scoped ApiKeys service after registration. Host code controls authentication, tenant resolution, HTTP routes, middleware and how a new credential is delivered.

## Issuance and verification

```php
use Evolve\ApiKeys\ApiKeyIssueRequest;
use Evolve\ApiKeys\ApiKeyVerificationRequest;
use Evolve\ApiKeys\ApiKeys;

$keys = $container->get(ApiKeys::class);
$issued = $keys->issue(new ApiKeyIssueRequest(
    'admin-1', 'user-1', 'tenant-1', ['orders.read'],
    new DateTimeImmutable('2030-01-01T00:00:00Z'),
));
$publicId = $issued->identifier();
$credential = $issued->credential(); // Show once to the intended recipient.

$result = $keys->verify(new ApiKeyVerificationRequest(
    $credential, 'user-1', 'tenant-1', ['orders.read'],
    new DateTimeImmutable('now'),
));
if (!$result->allowed()) {
    // Return one uniform denial; do not expose credential existence or reason.
}

$keys->revoke('admin-1', $publicId, 'user-1', 'tenant-1');
```

The credential format is ak1.<32 lowercase hex identifier>.<64 lowercase hex secret>. random_bytes(32) provides 256 secret bits; the SQL store persists only its SHA-256 digest. The public ID and secret are separate. Normal JSON and debug serialization of the issuance result omit the credential, and PHP object serialization is rejected. An explicit credential() call returns it. Credential and verifier constructor arguments use SensitiveParameter so exception traces redact those arguments when PHP argument capture is enabled. Do not log or persist the explicit return value.

Verification uses a fresh database read and a constant-time digest comparison. It denies malformed or unknown credentials, wrong principal, tenant or scopes, expired credentials and revoked credentials with the same public result. Expiration is evaluated on each verification and needs no background job. Revocation uses a conditional SQL update; after a successful commit, subsequent reads through an adapter that observes that commit deny the key. The database contract does not provide stronger cross-node consistency guarantees. Duplicate identifiers and storage or transaction failures surface as failures; the host must treat them as denial and avoid exposing internal details.

Diagnostic labels are catalog-backed and contain no credential values. The package does not create HTTP endpoints, perform migrations automatically, cache authentication state or resolve tenant identity.