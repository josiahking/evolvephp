---
title: Package Overview
description: Map of first-party source packages and their current ownership.
sidebar_position: 1
---

# Package overview

The current 2.x source declares the following first-party packages in [release-packages.json](https://github.com/josiahking/evolvephp/blob/2.x/release-packages.json). This is a source map, **not** a statement that packages are independently published. Every linked README describes a package's implemented scope and limits.

## Foundation and application composition

| Package | Role |
| --- | --- |
| [contracts](https://github.com/josiahking/evolvephp/blob/2.x/packages/contracts/README.md) | Shared runtime-neutral public contracts and value types. |
| [core](https://github.com/josiahking/evolvephp/blob/2.x/packages/core/README.md) | Configuration, service registry, execution, lifecycle and CLI foundations. |
| [module](https://github.com/josiahking/evolvephp/blob/2.x/packages/module/README.md) / [plugin](https://github.com/josiahking/evolvephp/blob/2.x/packages/plugin/README.md) | Component descriptors and explicit composition foundations. |

## Infrastructure contracts and adapters

| Package | Role |
| --- | --- |
| [database-contracts](https://github.com/josiahking/evolvephp/blob/2.x/packages/database-contracts/README.md) / [database-pdo](https://github.com/josiahking/evolvephp/blob/2.x/packages/database-pdo/README.md) | SQL contract and caller-owned PDO adapter. |
| [cache-memory](https://github.com/josiahking/evolvephp/blob/2.x/packages/cache-memory/README.md) | Local in-memory PSR-16 cache. |
| [session-contracts](https://github.com/josiahking/evolvephp/blob/2.x/packages/session-contracts/README.md) | Session boundary without a storage adapter. |
| [lock-contracts](https://github.com/josiahking/evolvephp/blob/2.x/packages/lock-contracts/README.md) | Lock/lease boundary without a concrete provider. |
| [queue-contracts](https://github.com/josiahking/evolvephp/blob/2.x/packages/queue-contracts/README.md) / [queue-memory](https://github.com/josiahking/evolvephp/blob/2.x/packages/queue-memory/README.md) | Queue boundary and non-durable local adapter. |
| [storage-contracts](https://github.com/josiahking/evolvephp/blob/2.x/packages/storage-contracts/README.md) / [storage-local](https://github.com/josiahking/evolvephp/blob/2.x/packages/storage-local/README.md) | Object-storage boundary and local filesystem adapter. |
| [secret-contracts](https://github.com/josiahking/evolvephp/blob/2.x/packages/secret-contracts/README.md) | Secret resolution boundary without a provider adapter. |

## HTTP, work and presentation

| Package | Role |
| --- | --- |
| [http](https://github.com/josiahking/evolvephp/blob/2.x/packages/http/README.md) / [http-client](https://github.com/josiahking/evolvephp/blob/2.x/packages/http-client/README.md) | Server HTTP foundations and PSR-18 client composition. |
| [job](https://github.com/josiahking/evolvephp/blob/2.x/packages/job/README.md) / [scheduler](https://github.com/josiahking/evolvephp/blob/2.x/packages/scheduler/README.md) / [migration](https://github.com/josiahking/evolvephp/blob/2.x/packages/migration/README.md) | One-shot job, one-tick scheduling and explicit migration foundations. |
| [view](https://github.com/josiahking/evolvephp/blob/2.x/packages/view/README.md) / [view-twig](https://github.com/josiahking/evolvephp/blob/2.x/packages/view-twig/README.md) / [view-blade](https://github.com/josiahking/evolvephp/blob/2.x/packages/view-blade/README.md) | Native view composition and optional template adapters. |
| [i18n](https://github.com/josiahking/evolvephp/blob/2.x/packages/i18n/README.md) | Explicit locale policy, catalogs and translation. |
| [audit-log](https://github.com/josiahking/evolvephp/blob/2.x/packages/audit-log/README.md) | Explicit audit events, tenant-scoped SQL persistence, authorized reads and caller-triggered retention; local source only. |
| [webhooks](webhooks.md) | Explicit tenant-scoped outbound webhook publication and signed delivery; requires a reviewed host transport. |
| [api-keys](api-keys.md) | Explicit tenant-bound API key issuance, verification and revocation; local source only. |

## Integration and operations

| Package | Role |
| --- | --- |
| [bridge-contracts](https://github.com/josiahking/evolvephp/blob/2.x/packages/bridge-contracts/README.md) / [bridge-psr](https://github.com/josiahking/evolvephp/blob/2.x/packages/bridge-psr/README.md) | Delegation contracts and PSR embedded adapter. |
| [bridge-laravel](https://github.com/josiahking/evolvephp/blob/2.x/packages/bridge-laravel/README.md) / [bridge-symfony](https://github.com/josiahking/evolvephp/blob/2.x/packages/bridge-symfony/README.md) | Host-specific embedded adapters. |
| [bridge-remote](https://github.com/josiahking/evolvephp/blob/2.x/packages/bridge-remote/README.md) | Versioned remote HTTP JSON client/server boundary. |
| [insight](https://github.com/josiahking/evolvephp/blob/2.x/packages/insight/README.md) / [observe](https://github.com/josiahking/evolvephp/blob/2.x/packages/observe/README.md) | Local diagnostics and optional OpenTelemetry integration. |
| [mcp](https://github.com/josiahking/evolvephp/blob/2.x/packages/mcp/README.md) | Experimental MCP server composition and restricted transports; explicit client discovery and invocation over policy-checked JSON-only HTTPS or Linux PHP STDIO. Native Windows PHP client STDIO, SSE and stateful sessions are deferred. |
| [dev-tools](https://github.com/josiahking/evolvephp/blob/2.x/packages/dev-tools/README.md) / [testing](https://github.com/josiahking/evolvephp/blob/2.x/packages/testing/README.md) | Development tooling and test support. |

The [repository package index](https://github.com/josiahking/evolvephp/blob/2.x/packages/README.md) covers dependencies and limitations in more detail. Optional packages are not implicitly installed or wired by Core.

## Current guides

This v1 manual has focused [MCP](mcp.md), [Audit Log](audit-log.md), [Redis PSR-16 compatibility](redis.md), [Symfony Mailer compatibility](symfony-mailer.md), [Monolog PSR-3 compatibility](monolog.md), [Symfony Validator compatibility](symfony-validator.md), [Symfony Rate Limiter compatibility](symfony-rate-limiter.md), [Webhooks](webhooks.md), and [API Keys](api-keys.md) guides and guides for selected foundations and modernization features. Redis, Symfony Mailer, Monolog, Symfony Validator and Symfony Rate Limiter are optional upstream integrations with application-owned registration; none is a first-party EvolvePHP package. The package list above remains a source map; it does not promise a full tutorial for every first-party package.

MCP operational events are disabled by default. Hosts may wire safe normalized events to Observe telemetry or Insight local diagnostics without adding package dependency cycles.
