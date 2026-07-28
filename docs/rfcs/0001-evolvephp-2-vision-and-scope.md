# RFC 0001: EvolvePHP 2 Vision, Scope and Non-Goals

- Status: Accepted
- Authors: Josiah Chinedu Gerald
- Created: 2026-07-28
- Target release: EvolvePHP 2.0
- Decision type: Product and architecture direction
- Supersedes: None
- Superseded by: None

## 1. Summary

EvolvePHP 2 is a cloud-ready, plugin-first PHP framework for modular SaaS applications. It helps teams build a clean modular monolith, operate it in modern environments, and extract selected modules into workers or services when growth requires it.

EvolvePHP 2 is a separate redesign. It is not an in-place refactor of EvolvePHP 1, and RFC 0001 does not modernise or rewrite the preserved EvolvePHP 1 runtime.

## 2. Problem Statement

PHP applications often become tightly coupled as they grow. Modular monolith boundaries are commonly informal or unenforced, and framework code, infrastructure code and business logic can become difficult to separate.

Teams may adopt microservices too early because their monolith lacks safe extraction boundaries. EvolvePHP 2 should make the modular-monolith path explicit before distributed-service complexity is introduced.

Existing Laravel, Symfony and legacy PHP applications also need incremental modernisation paths. EvolvePHP 2 should be a focused alternative and interoperability layer, not an attack on Laravel, Symfony or other established frameworks.

Observability is frequently added late rather than designed into the application lifecycle. Persistent workers and modern deployment environments expose hidden global-state problems. Plugin ecosystems also often lack explicit lifecycle, compatibility and security contracts.

## 3. Vision

An EvolvePHP module can run embedded in an application, isolated in a worker, or exposed as a remote service without changing its domain logic.

This is the north-star architecture direction for EvolvePHP 2. It is a long-term goal, not a claim that embedded, worker and remote-service execution will all exist in the first alpha release.

## 4. Target Users

Primary users:

- Experienced PHP developers.
- Backend teams building modular SaaS applications.
- Teams that want a modular monolith before considering distributed services.
- Laravel and Symfony teams introducing isolated new capabilities.
- Teams modernising old PHP systems one feature at a time.
- Product teams that need observability, testing and explicit architectural boundaries.
- Maintainers who value predictable behaviour over hidden framework magic.

Secondary users:

- Framework and package authors.
- Internal platform teams.
- Consultants modernising business-critical PHP systems.
- Teams building reusable private modules.

EvolvePHP 2 is not initially optimised as the easiest first framework for someone who has never used PHP.

## 5. Core Promises

### Modular Monolith First

Applications should begin as well-structured modular monoliths. Microservices are not the default architecture.

### Plugin-First Extensibility

Framework capabilities and application extensions should use explicit plugin and module contracts.

### Explicit Public Contracts

Important framework behaviour must be represented through reviewed public interfaces and documented lifecycle rules.

### Replaceable Infrastructure

Database, cache, queue, storage, logging, telemetry and related infrastructure must sit behind replaceable contracts where appropriate.

### Test-Driven Development

Framework behaviour must be developed with:

```text
RED -> GREEN -> REFACTOR
```

### Observability By Design

Local diagnostics and production telemetry must be designed into framework lifecycle operations.

### Incremental Modernisation

Teams should be able to introduce EvolvePHP without rewriting an existing Laravel, Symfony or legacy PHP application.

### Modern Runtime Safety

Request-scoped and tenant-scoped state must not leak across requests, workers or persistent runtime executions.

### Standards Interoperability

EvolvePHP 2 should use established PHP standards where they provide meaningful interoperability. This RFC does not promise compliance with standards that have not yet been selected or implemented.

## 6. Product Vocabulary

### Evolve Core

Evolve Core is the minimal framework contracts, kernel lifecycle and foundational runtime behaviour.

### Evolve Modules

Evolve Modules are application business capabilities with explicit ownership and dependency boundaries.

### Evolve Plugins

Evolve Plugins are framework extensions that participate through documented registration, boot and shutdown lifecycles.

### Evolve Insight

Evolve Insight is local development and diagnostic tooling comparable in purpose to a framework request inspector. Insight may inspect requests, queries, events, jobs, cache activity, exceptions and framework lifecycle timings. Evolve Insight is not the production telemetry backend.

### Evolve Observe

Evolve Observe is production observability integration based on OpenTelemetry concepts such as traces, metrics, logs and context propagation. It should export telemetry to external backends. Evolve Observe is not intended to become Datadog, New Relic, Grafana or another full observability backend replacement.

### Evolve Bridge

Evolve Bridge is the planned incremental-modernisation adapter family for:

- PSR-compatible hosts.
- Laravel.
- Symfony.
- Remote HTTP integration.
- Legacy PHP clients.

### Evolve Runtime

Evolve Runtime is the execution adapter direction for supported web, CLI and worker environments.

### Evolve Deploy

Evolve Deploy is a future deployment product and tooling direction. It is not part of the initial EvolvePHP 2.0 framework core.

None of these products or product areas are defined here as already implemented.

## 7. Module Versus Plugin Distinction

### Module

A module represents an application or business capability.

Examples:

- Billing.
- Identity.
- Reporting.
- Notifications.
- Audit.
- Customer management.

A module may contain:

- Domain rules.
- Application use cases.
- Contracts.
- Infrastructure adapters.
- HTTP or CLI presentation adapters.
- Tests.

### Plugin

A plugin extends framework or platform capabilities.

Examples:

- Queue adapter.
- Storage adapter.
- Telemetry exporter.
- Authentication integration.
- Developer tool.
- Host framework bridge.

Later RFCs will define exact lifecycle and dependency rules. RFC 0001 establishes vocabulary; it does not finalise implementation-level APIs.

## 8. PHP Support Policy

### EvolvePHP 2.0 Requirement

```text
Minimum PHP: 8.4
Initially tested: PHP 8.4 and PHP 8.5
```

The recommended production PHP version is the newest officially supported branch validated by the EvolvePHP CI matrix.

### Major-Version Policy

Each EvolvePHP major version supports PHP branches that are still officially supported when that EvolvePHP major reaches stable release.

### Unsupported PHP Versions

Unsupported PHP versions, including PHP 7, are not supported inside Evolve Core.

Legacy systems must integrate through:

- Reverse-proxy delegation.
- HTTP APIs.
- Queues.
- Integration events.
- A deliberately small remote client.

Do not add PHP 7 compatibility code to EvolvePHP Core.

## 9. Existing-Framework Interoperability

EvolvePHP 2 does not require replacing Laravel or Symfony.

### Same-Process Embedded Mode

Same-process embedded mode is for compatible modern host applications running a supported PHP version and compatible dependencies.

The host framework owns:

- Top-level application lifecycle.
- Session.
- Authentication.
- Existing routes.
- Top-level error handling.

EvolvePHP owns only the selected feature-module lifecycle.

### Sidecar Or Separately Deployed Mode

Sidecar or separately deployed mode is for:

- PHP 7 systems.
- Dependency conflicts.
- Independent scaling.
- Independent releases.
- Stronger runtime isolation.

### Headless Remote-Module Mode

In headless remote-module mode, the host application communicates through:

- HTTP.
- Queues.
- Integration events.
- Future remote protocols where justified.

Evolve Bridge is planned for the EvolvePHP 2.0 Beta direction, after stable core, HTTP, module and plugin contracts exist.

## 10. EvolvePHP 2.0 Scope

### 2.0 Alpha

The alpha includes only foundational capabilities:

- Repository and package structure.
- Public contracts.
- Container and dependency registration foundations.
- Application kernel lifecycle.
- Request-scoped state boundaries.
- Basic HTTP request and response lifecycle.
- Routing foundations.
- Middleware foundations.
- Configuration foundations.
- Error and exception foundations.
- Testing utilities.
- Initial module registration.
- Initial plugin lifecycle.
- Basic native view rendering foundations.
- Architecture validation.

The alpha does not need every infrastructure adapter or bridge.

### 2.0 Beta

The beta may include:

- Stabilised module and plugin SDKs.
- Evolve Bridge foundations.
- Laravel bridge.
- Symfony bridge.
- PSR-compatible bridge.
- Remote-client foundations.
- Evolve Insight MVP.
- Evolve Observe and OpenTelemetry integration.
- Infrastructure contracts and initial adapters.
- Docker development and deployment reference assets.
- First-party reference modules.
- Documentation and upgrade guidance.

### 2.0 Stable

Stable requires:

- Reviewed public APIs.
- Semantic versioning policy.
- Compatibility policy.
- Complete automated tests for supported behaviour.
- CI validation on supported PHP versions.
- Security review.
- Documentation for application development.
- Documentation for plugin and module authors.
- Stable example application.
- Stable error handling.
- Stable lifecycle reset behaviour.
- Stable bridge contracts included in 2.0.
- No unresolved critical architecture decisions.

Kubernetes, managed hosting and mature distributed-service orchestration are not required for initial 2.0 stable.

## 11. Deferred Capabilities

Release assignment may change through later RFCs, but these capabilities are not required for EvolvePHP 2.0 acceptance.

### EvolvePHP 2.1 Candidates

EvolvePHP 2.1 candidates include mature persistent-runtime adapters, FrankenPHP integration, RoadRunner integration, advanced worker management, multi-tenancy framework, additional infrastructure adapters, advanced deployment tooling, and private package or module registry foundations. FrankenPHP and multi-tenancy are not required for initial 2.0.

### EvolvePHP 2.2 Candidates

EvolvePHP 2.2 candidates include advanced service extraction, remote module adapters, outbox and inbox patterns, distributed command and query buses, cross-service workflow tooling, Kubernetes reference architecture, advanced autoscaling and service topology, and managed platform foundations. Kubernetes and advanced service extraction are not required for initial 2.0.

## 12. Explicit Non-Goals

- EvolvePHP 2 will not be a Laravel clone.
- EvolvePHP 2 will not be a Symfony clone.
- It will not recreate every feature of established frameworks in 2.0.
- It will not require microservices.
- It will not encourage premature service extraction.
- It will not build a custom ORM in the initial core unless a later RFC proves a compelling need.
- It will not build a custom dependency standard when an established PHP standard is sufficient.
- It will not become a JavaScript framework or frontend metaframework.
- It will not make Kubernetes mandatory.
- It will not make Docker mandatory for local development.
- It will not support PHP 7 inside Evolve Core.
- It will not promise automatic horizontal scalability without application design discipline.
- It will not store all production telemetry in the application database.
- Evolve Observe will not compete directly with full observability vendors.
- Evolve Deploy will not be required for using the open-source framework.
- The framework will not hide all infrastructure complexity through uncontrolled magic.
- The framework will not guarantee that every module can become a remote service without adapter work.
- EvolvePHP 2 will not rewrite or silently modernise EvolvePHP 1.

## 13. Open-Source and Commercial Boundary

Free framework, paid operational convenience, enterprise confidence and hosted services.

The following should remain part of the open-source framework direction:

- Core runtime.
- Module and plugin SDKs.
- Testing utilities.
- CLI foundations.
- Local Insight tooling.
- OpenTelemetry exporters and integration.
- Docker reference assets.
- Health checks.
- Basic framework adapters.
- Security foundations.
- Documentation.
- Reference application.

Potential future paid products may include:

- Evolve Observe Cloud.
- Evolve Deploy.
- Enterprise support.
- Premium business modules.
- Private registry.
- Training and certification.
- Marketplace services.
- Sponsored development.

Commercial products must not make the free framework artificially unusable. This RFC does not claim that paid products already exist.

## 14. Architecture Principles

- Composition over inheritance.
- Explicit dependencies.
- Constructor injection.
- No uncontrolled service locator usage.
- Public contracts before implementations.
- Domain logic independent from delivery mechanism.
- Module-owned data and behaviour.
- Infrastructure behind adapters.
- Fail-fast configuration.
- Secure defaults.
- Testability as a design constraint.
- Observability as a lifecycle concern.
- No request-specific state in unsafe global statics.
- Persistent-worker reset safety.
- Minimal core.
- Optional features installed separately.
- Backward compatibility for documented public APIs.
- Internal implementation freedom where public behaviour is preserved.

## 15. Success Criteria

Success means more than publishing code:

- Developers can understand the module and plugin model from documentation.
- A small application can serve an HTTP request through the kernel.
- Request-scoped state is reset safely.
- A module can expose a tested use case without depending directly on framework globals.
- An application can record local Insight diagnostics.
- An application can emit an OpenTelemetry trace.
- A supported host application can invoke an Evolve feature through an approved bridge.
- Public APIs are tested and documented.
- Framework behaviour is validated on supported PHP versions.
- New features follow TDD and repository agent rules.

## 16. Initial Technical Milestone

One tested HTTP request must pass through the EvolvePHP kernel, produce a response, record an Insight diagnostic batch, create an OpenTelemetry trace and reset all request-scoped state.

This milestone spans multiple roadmap phases. RFC 0001 does not implement it.

## 17. Alpha Acceptance Criteria

The initial alpha can be declared only when:

1. PHP 8.4 is enforced.
2. Public foundational contracts are documented.
3. Kernel lifecycle behaviour is tested.
4. HTTP request and response handling is tested.
5. Middleware ordering and failure behaviour are tested.
6. Request-scoped state reset is tested.
7. Initial module registration is tested.
8. Initial plugin registration is tested.
9. Basic configuration and error behaviour are tested.
10. CI executes the test suite on supported PHP versions.
11. No critical architecture RFC remains undecided for alpha scope.
12. Documentation does not claim unimplemented features.

## 18. Decision Governance

- RFC 0001 is authoritative for product scope and positioning.
- Later RFCs may refine implementation details.
- A later RFC must explicitly state when it supersedes a decision in RFC 0001.
- Public API changes require review.
- Major architectural changes require an RFC.
- Accepted RFCs should not be silently edited to reverse decisions.
- Material reversals should use a new RFC that supersedes the previous one.
- Editorial corrections may be made without a new RFC when they do not alter the decision.

## 19. Consequences and Tradeoffs

### Positive

- Clear scope before implementation.
- Reduced rewrite risk.
- Better modular boundaries.
- Easier incremental adoption.
- Explicit compatibility policy.
- Better long-term service extraction path.
- Stronger test and observability discipline.

### Negative

- Slower initial visible feature delivery.
- Additional contract design work.
- More documentation and governance overhead.
- Framework bridges increase maintenance responsibility.
- Supporting persistent runtimes requires strict state discipline.
- Avoiding framework magic may require more explicit application code.

## 20. Alternatives Considered

### Modernise EvolvePHP 1 In Place

Rejected because the original architecture is tightly coupled to globals, dynamic dispatch, legacy dependencies and PHP 7-era assumptions.

### Build A Laravel Package Only

Rejected as the sole direction because EvolvePHP aims to support independent execution and Symfony/legacy interoperability. Laravel integration remains valuable through Evolve Bridge.

### Build Microservices First

Rejected because it creates operational complexity before module boundaries and product demand are proven.

### Build A Managed Cloud Platform First

Rejected because adoption, framework stability and recurring demand must be established before expensive platform operations.

### Support PHP 7 In The New Core

Rejected because it weakens security, dependency compatibility, typing and runtime-safety goals.
