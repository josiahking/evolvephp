# EvolvePHP API Keys

Composer package: `evolvephp/api-keys`; requires PHP `^8.4`.

Explicit, tenant-bound API key issuance, verification and revocation.

EvolvePHP 2 is pre-release. This package is local source only and is not yet independently published or registered on Packagist. The canonical source is the EvolvePHP monorepo at https://github.com/josiahking/evolvephp.

First-party dependencies: `evolvephp/contracts`, `evolvephp/database-contracts`, `evolvephp/i18n`, `evolvephp/migration`, `evolvephp/module`. PSR Container is also required for Module SDK factory signatures.

The BSD-3-Clause package licence is in `LICENSE.md`.


Experimental, source-only EvolvePHP 2 module. The host must explicitly register ApiKeysModule in a ModuleDefinition, supply DatabaseConnection and ApiKeyAccessPolicy services, and run ApiKeysMigrationContributor through the migration runner. Registration and boot do not run SQL.

Use the execution-scoped ApiKeys service. An authorized issue() call returns an IssuedApiKey; show credential() to the intended recipient once and store only identifier() for management. Verification takes an explicit credential, principal, tenant, required scopes and time. A denied result exposes only allowed() = false. Expiration is checked during verification, without a job. Authorized revoke() updates storage and subsequent fresh reads deny the key after commit.

PHP object serialization of credential-bearing and verifier-bearing objects is rejected. Credential and verifier constructor arguments use SensitiveParameter so exception traces redact those arguments when PHP argument capture is enabled. An explicit credential() call still returns the secret and must be handled as sensitive. The credential has format ak1.<32 lowercase hex identifier>.<64 lowercase hex secret>. The secret contains 256 random bits from random_bytes(). Only its SHA-256 digest is persisted. The public identifier is separate from the secret. SQL storage is transaction-backed through the DatabaseConnection contract, with bound values. Visibility follows the database adapter's committed-read consistency; no stronger cross-node concurrency guarantee is claimed.

The migration requests database transaction mode; DDL rollback beyond the tested SQLite adapter is unverified and hosts must check their database before applying it.

The host owns authentication, actor authorization, tenant resolution, HTTP routes and middleware, and credential delivery. The package grants no access by default and has no automatic discovery, migrations or background worker. Treat the issuance return value as sensitive; do not log, persist, or serialize its credential explicitly. Package diagnostic labels never include credentials.