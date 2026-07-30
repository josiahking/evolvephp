# Changelog

## Unreleased

### Documentation and governance

- Added Cross-RFC terminology harmonization, Bridge dependency-direction clarification, execution-scoped tenant-context clarification, and telemetry finalization and scope-closure ordering clarification for accepted EvolvePHP 2 RFCs 0001-0006.
- Added `AGENTS.md` as the mandatory workflow rule source for coding agents working in this repository.
- Added EvolvePHP 1 legacy preservation documentation under `docs/history/`.
- Added RFC 0001: EvolvePHP 2 Vision, Scope and Non-Goals.
- Added RFC 0002: Terminology, Package Boundaries and Public Contracts.
- Added RFC 0003: PHP Versioning, Compatibility and Release Policy.
- Added RFC 0004: Module and Plugin Lifecycle.
- Added RFC 0005: Request Scope, Runtime Reset and Persistent-Worker Safety.
- Added RFC 0006: Evolve Bridge and Incremental Modernisation.
- Added `SECURITY.md` with private vulnerability-reporting guidance and supported-line status.
- Added `SUPPORT.md` with usage, bug-report and security-report guidance.
- Added documentation-policy tests for the Phase 0 preservation baseline.

### Legacy baseline

- Preserved the audited EvolvePHP 1 baseline at commit `2da5da7866f65d314a0e2bf10b572004b3014d60`.
- No `1.0.0-legacy` Git tag was found during the Phase 0 audit.
- No legacy runtime modernization, dependency upgrade, PHP requirement change or namespace rename is included in this baseline documentation work.
