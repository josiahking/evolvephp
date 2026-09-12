# Status And Limitations

EvolvePHP 2 is Alpha/pre-release software. It is not production-ready and is not recommended for production use. Experimental APIs may change, including breaking changes, before a stable release.

## Implemented

- PHP 8.4 framework minimum.
- Current official framework CI evidence on PHP 8.4 and PHP 8.5.
- Runtime-neutral Core foundations for configuration, service registry, execution scopes, reset behavior, execution orchestration, component lifecycle and CLI commands.
- HTTP foundations for middleware, routing, routed dispatch, HTTP execution, response resolution and explicit response emission boundary.
- Module and plugin descriptors, dependency/capability graph support, restricted service registration, Composer plugin discovery and application-controlled enablement.
- Development-time Audit, adoption planning declarations, Doctor support, `route:list` and optional starter generators.
- Bridge contracts, PSR, Laravel, Symfony, remote protocol/client/server foundations and the isolated legacy HTTP client.

## Experimental

- Public APIs remain experimental.
- Bridge compatibility depends on documented and tested combinations. This documentation does not claim a compatibility matrix broader than the repository proves.
- Adoption planning evidence is declarative and review-oriented.

## Deferred

- Complete concrete web runtime and concrete SAPI adapter support.
- SAPI request creation, concrete response emission, production web bootstrap and deployment scaffolding.
- Public package publication and public Packagist installation.
- Automatic migration, automatic cutover and automatic rollback.
- Distributed transaction coordination across host and Evolve boundaries.
- No LTS support policy and no production support SLA.

## Unsupported Or Not Claimed

- First-party packages are not yet independently published.
- No public Packagist installation is available yet.
- No current public create-project application installation is claimed.
- The isolated legacy PHP 7.4 client is a remote compatibility exception only. It does not lower the PHP 8.4 framework baseline and does not allow PHP 7.4 to run EvolvePHP Core or embedded Bridge.
- EvolvePHP 2 does not claim production readiness, commercial support, broad host-framework compatibility, automatic retries/fallback or shared sessions/cookies across remote Bridge.
