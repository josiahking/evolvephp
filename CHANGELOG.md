# Changelog

## Unreleased

### Documentation and governance

- Added Phase 2.7A repository-owned branch-governance policy foundation for EvolvePHP 2 development on `2.x`, preserved EvolvePHP 1 maintenance on `master`, explicit separation of modern development and legacy maintenance, and preparation for a later default-branch and ruleset transition.
- Added Phase 2.6 GitHub Actions CI foundation with a PHP 8.4/8.5 workspace quality matrix, separate PHP 8.4 root-policy job, lockfile-based workspace installation, immutable action pinning, and successful initial CI execution for the current workspace, tooling and package foundation.
- Added Phase 2.5.1 README and metadata consistency cleanup, aligning the EvolvePHP 2 README hierarchy, correcting root Composer metadata, deduplicating stale RFC index narration, removing duplicated phase history, and clarifying workspace installation and compatibility guidance.
- Added Phase 2.5 workspace-owned Deptrac architecture and dependency-boundary enforcement for the initial EvolvePHP 2 package graph.
- Added Phase 2.4 workspace-owned PHPStan level 6 static analysis, PHPUnit type-inference integration, PHP-CS-Fixer PER Coding Style 3.0 checks, and architecture-policy tests for cache, baseline and tooling ownership.
- Added Phase 2.3 PHPUnit 13 workspace foundation, six package test suites, and the initial workspace lockfile generated under PHP 8.4.
- Added Phase 2.2 dedicated EvolvePHP 2 Composer workspace with local path-repository integration and explicit 2.0.x-dev package version mapping.
- Added Phase 2.1 initial EvolvePHP 2 package skeleton boundaries for the accepted alpha package map.
- Added Cross-RFC terminology harmonization, Bridge dependency-direction clarification, execution-scoped tenant-context clarification, and telemetry finalization and scope-closure ordering clarification for accepted EvolvePHP 2 RFCs 0001-0006.
- Added `AGENTS.md` as the mandatory workflow rule source for coding agents working in this repository.
- Added EvolvePHP 1 legacy preservation documentation under `docs/history/`.
- Added RFC 0001: EvolvePHP 2 Vision, Scope and Non-Goals.
- Added RFC 0002: Terminology, Package Boundaries and Public Contracts.
- Added RFC 0003: PHP Versioning, Compatibility and Release Policy.
- Added RFC 0004: Module and Plugin Lifecycle.
- Added RFC 0005: Request Scope, Runtime Reset and Persistent-Worker Safety.
- Added RFC 0006: Evolve Bridge and Incremental Modernisation.
- Added RFC 0007: Insight and OpenTelemetry Architecture.
- Added `SECURITY.md` with private vulnerability-reporting guidance and supported-line status.
- Added `SUPPORT.md` with usage, bug-report and security-report guidance.
- Added documentation-policy tests for the Phase 0 preservation baseline.

### Legacy baseline

- Preserved the audited EvolvePHP 1 baseline at commit `2da5da7866f65d314a0e2bf10b572004b3014d60`.
- No `1.0.0-legacy` Git tag was found during the Phase 0 audit.
- No legacy runtime modernization, dependency upgrade, PHP requirement change or namespace rename is included in this baseline documentation work.
