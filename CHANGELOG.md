# Changelog

## Unreleased

### Documentation and governance

- Added Phase 2.10B deterministic package split and prerelease consumer validation with repository-owned `git subtree` split checks, exact tree/inventory equivalence, generated split Composer validation, an offline alpha/stable consumer matrix, documented alpha consumer policy, retained internal `^2.0` constraints, Policy PHP 8.4 split-validation enforcement, manual/pre-release consumer validation, and no remote writes, tags or release artifacts.
- Added Phase 2.10A deterministic package release validation with an explicit release package map/order, package-local README/licence preparation, and no remote publication or splitting yet.
- Added Phase 2.9A supply-chain security foundation with Composer lockfile security audit enforcement, abandoned-package failure, locked production and development licence-policy checks for MIT, BSD-3-Clause and Apache-2.0, repository-owned Dependabot version-update configuration, Policy job enforcement, and documentation of GitHub setting boundaries.
- Added Phase 2.8 developer-experience foundation with EditorConfig, VS Code extension recommendations, portable VS Code settings and portable task commands, PHP 8.4 language-analysis targeting, canonical Composer-script reuse, explicit non-mutating quality checks versus the Style Fix mutating task, no local executable paths, and no runtime debugging configuration.
- Added Phase 2.7B repository governance evidence finalization: changed the GitHub default branch to `2.x`, activated repository rulesets for `master` and `2.x`, preserved `master` as the EvolvePHP 1 legacy line, required PR-based change on both branches, blocked deletion and force pushes, enforced strict/up-to-date required status checks on `2.x` for `Policy (PHP 8.4)`, `Workspace quality (PHP 8.4)` and `Workspace quality (PHP 8.5)`, and made no branch rename or deletion.
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
