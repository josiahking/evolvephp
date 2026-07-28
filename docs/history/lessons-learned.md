# Lessons Learned from EvolvePHP 1

This document summarizes lessons from the original framework as verified during the Phase 0 audit. It describes how those lessons can shape EvolvePHP 2 without claiming that EvolvePHP 2 features already exist.

## What worked

### Composer PSR-4 adoption

`composer.json` maps core, component and helper namespaces through PSR-4. This gave the project a recognizable package structure and avoided a purely ad hoc include model.

### Reusable components

The `components/` directory separates application areas into component folders with controllers, models and views. The audited repository includes `site` and `error` components.

### Separation into core, components and helpers

The repository distinguishes framework foundations in `core/`, reusable helper utilities in `helpers/`, and application components in `components/`. That separation made the original framework easier to navigate than a single flat application directory.

### Production use

The README and maintainer-provided project history report use as the foundation of Africa Global Export Market, a live business-to-business export marketplace with more than 5,000 registered users.

### Framework ownership experience

The codebase shows direct ownership of routing, configuration, session handling, view rendering, logging, error handling, models and helpers. That experience is valuable input for a second-generation design.

### Routing and application lifecycle experimentation

`index.php` and `route.php` implement a front-controller flow with CORS preprocessing, PHP-version checks, configuration loading, environment selection, route sanitisation and controller dispatch.

### Reusable backend infrastructure

The framework contains reusable foundations for PDO access, view layouts, sessions, exceptions and logging. Even where those foundations are limited, they show the practical concerns the original framework tried to centralize.

## What became limiting

### Global state

The runtime depends on constants, `$_SERVER`, `$_GET`, `$_POST`, `$_SESSION`, `header()`, `session_*()` functions and direct output. This makes testing, request isolation and long-running execution harder.

### Tight coupling

Controllers, core factories and helpers instantiate dependencies directly or call static helpers. There is no container or explicit composition root.

### Dynamic dispatch

`route.php` invokes controller methods from route segments. This expands the public method surface and makes routing rules less explicit.

### Configuration constants

Configuration is defined as PHP constants. Constants are simple, but they are difficult to replace per request, per environment, per test or per tenant.

### Lack of explicit contracts

The current code has a logging interface, but request handling, response generation, routing, middleware, controller actions, configuration and data access do not have comprehensive contracts.

### Lack of request and response abstractions

HTTP input and output are handled through globals and direct header/body emission. This limits testability and PSR interoperability.

### Limited automated tests

Before Phase 0, the `tests/` directory contained only `tests/index.html`. Runtime behaviour does not have meaningful regression coverage in the preserved baseline.

### Infrastructure coupled to framework internals

Logging, session handling, routing, view loading and database access are coupled to constants, file paths and direct framework classes.

### Difficult horizontal scaling

File-based logging assumptions, PHP sessions, global state and local configuration require extra design before multi-server or cloud-native operation.

### Lack of modern observability

The preserved runtime writes errors through PHP error logs and log4php, but it does not define structured framework events, metrics, tracing, request IDs or lifecycle telemetry.

### Lack of extension lifecycle contracts

Components exist, but the repository does not define module/plugin installation, boot, registration, dependency, configuration or teardown contracts.

## How the lessons shape EvolvePHP 2

### Modular monolith first

EvolvePHP 2 should preserve the useful idea of organized modules while starting with a modular monolith that has clear boundaries before distributed complexity is introduced.

### Plugin and module contracts

Components should evolve into explicit module or plugin contracts with lifecycle hooks, dependency declarations, registration points and compatibility rules.

### Ports and adapters

Infrastructure such as logging, persistence, sessions, mail, queues and cache should sit behind ports so implementations can be replaced without rewriting framework internals.

### PSR interoperability

Request, response, middleware, logging and container decisions should consider PSR interoperability so EvolvePHP 2 can integrate with the broader PHP ecosystem.

### TDD

Phase 0 introduces documentation-policy tests. EvolvePHP 2 should continue with RED -> GREEN -> REFACTOR for behavioural implementation, not only for governance files.

### Observability

Framework lifecycle operations should expose structured events, logs and metrics where the roadmap requires them, without logging secrets or sensitive payloads.

### Cloud-ready execution

Configuration, sessions, logging and filesystem assumptions should be designed for containerized and horizontally scaled deployments.

### Persistent-worker safety

Long-running worker support requires explicit request state reset, memory behaviour tests and avoidance of hidden mutable global state.

### Evolve Bridge

Any bridge between EvolvePHP 1 applications and EvolvePHP 2 should be an explicit compatibility layer, not an unreviewed in-place rewrite of the legacy runtime.

### Incremental modernisation

Modernisation should be incremental and evidence-driven. Legacy behaviour should be documented before it is changed, and risky changes should be covered by tests.

### Explicit version-support policy

EvolvePHP 2 should define supported versions, support windows and security expectations early so users understand the difference between legacy maintenance and active development.
