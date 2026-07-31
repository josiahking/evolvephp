# EvolvePHP RFCs

## Purpose

This directory records material product, governance and architecture decisions for EvolvePHP. RFCs should make important decisions reviewable, durable and easy to reference before implementation work begins.

## Status vocabulary

- Draft: early text that is still being shaped.
- Proposed: ready for review, but not yet adopted.
- Accepted: adopted as the current decision.
- Rejected: considered and not adopted.
- Superseded: replaced by a later accepted RFC.

Accepted material architecture decisions must not be silently reversed. A later RFC should explicitly supersede an accepted RFC when a material decision changes.

## Index

- [RFC 0001: EvolvePHP 2 Vision, Scope and Non-Goals](0001-evolvephp-2-vision-and-scope.md) - Accepted
- [RFC 0002: Terminology, Package Boundaries and Public Contracts](0002-terminology-package-boundaries-and-public-contracts.md) - Accepted
- [RFC 0003: PHP Versioning, Compatibility and Release Policy](0003-php-versioning-compatibility-and-release-policy.md) - Accepted
- [RFC 0004: Module and Plugin Lifecycle](0004-module-and-plugin-lifecycle.md) - Accepted
- [RFC 0005: Request Scope, Runtime Reset and Persistent-Worker Safety](0005-request-scope-runtime-reset-and-persistent-worker-safety.md) - Accepted
- [RFC 0006: Evolve Bridge and Incremental Modernisation](0006-evolve-bridge-and-incremental-modernisation.md) - Accepted
- [RFC 0007: Insight and OpenTelemetry Architecture](0007-insight-and-opentelemetry-architecture.md) - Accepted

RFC 0001 defines product direction. RFC 0002 defines terminology, package boundaries and public API governance. RFC 0003 defines compatibility, versioning and release policy. RFC 0004 defines module and plugin lifecycle rules and application/module/plugin lifecycle. RFC 0004 defines application/module/plugin lifecycle. RFC 0005 will define execution scope and reset safety. RFC 0005 defines per-execution scope, reset and worker reuse. RFC 0005 defines execution scope, reset and context isolation. RFC 0006 will define Bridge integration for implementation planning. RFC 0006 defines host integration and incremental modernisation. RFC 0006 defines Bridge integration. RFC 0007 will define Insight and telemetry architecture. RFC 0007 will define Insight and OpenTelemetry architecture. RFC 0007 defines Insight, generic instrumentation and OpenTelemetry architecture. Later lifecycle and runtime RFCs may depend on these governance foundations.
