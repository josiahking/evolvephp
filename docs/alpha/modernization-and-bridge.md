# Modernization And Bridge

EvolvePHP 2 supports an incremental modernization path through read-only Audit evidence, adoption planning declarations and Bridge foundations. The path is explicit and bounded: one authoritative owner should exist for each capability and data set.

## Audit

Audit is read-only. It inspects an explicit target root as evidence, including Composer metadata, Composer lockfile data and PHP source structure/coupling signals. Audit does not execute target code, include target autoloaders, run Composer, bootstrap frameworks, load environment files or modify the target.

Audit findings are review evidence. They do not certify migration readiness, runtime compatibility, security status, persistent-worker safety or Bridge compatibility.

See the DevTools package documentation for current Audit details: [evolvephp/dev-tools](../../packages/dev-tools/README.md).

## Adoption Planning

Adoption planning is a bounded declaration model for one capability. It records explicit route ownership, data ownership and writer transitions, migration evidence, rollback evidence, compatibility requirements, identity/security requirements and acceptance criteria.

These declarations do not perform automatic migration, automatic cutover, automatic rollback, route discovery, ownership inference, data synchronization or compatibility certification.

## Embedded Bridge

Embedded Bridge uses a PSR foundation with host-specific adapters where implemented. The current embedded path includes the PSR adapter plus Laravel and Symfony host adapters.

Embedded mode requires a compatible same-process PHP runtime and Composer dependency graph. The host keeps the outer lifecycle: process startup, host routing, host container ownership, request acceptance and final response emission. Evolve owns delegated execution after the host explicitly selects delegation.

See [evolvephp/bridge-psr](../../packages/bridge-psr/README.md), [evolvephp/bridge-laravel](../../packages/bridge-laravel/README.md) and [evolvephp/bridge-symfony](../../packages/bridge-symfony/README.md).

## Remote And Sidecar Bridge

Remote Bridge uses a versioned HTTP JSON protocol across a process and dependency boundary. Sidecar deployment remains remote mode: it does not become same-process because it runs near the host.

Remote mode provides isolation for PHP versions and dependency graphs, but it introduces network failure and a security boundary. Authentication, authorization, endpoint trust, timeout, retry, fallback, idempotency and reconciliation policy belong to the application or deployment.

See [evolvephp/bridge-remote](../../packages/bridge-remote/README.md) and [RFC 0006](../rfcs/0006-evolve-bridge-and-incremental-modernisation.md).

## Legacy PHP

Legacy PHP support is limited to the isolated legacy HTTP client. It has official PHP 7.4 evidence and speaks the remote protocol only. It does not enable PHP 7 embedded EvolvePHP, and it does not lower the PHP 8.4 requirement for EvolvePHP 2 packages, Core or embedded Bridge.

See [legacy HTTP client](../../compat/legacy-http-client/README.md).

## Boundaries

There is no automatic migration, no automatic cutover, no shared sessions or cookies, no distributed transaction guarantee and no automatic retries or fallback unless the application or deployment supplies that policy. Route ownership and data ownership must remain explicit before traffic or writes move.
