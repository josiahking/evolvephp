# Automation and Contribution Guide

Every coding agent must read and follow this file as public automation guidance for this repository.

This guide applies to repository work performed by contributors, automation tools and coding assistants. It keeps automated changes reviewable, evidence-based and aligned with the public EvolvePHP branch model.

## Scope Discipline

- Work only on the requested task.
- Do not add adjacent features because they appear useful.
- Keep changes small and reviewable.
- Identify assumptions before implementation.
- Stop and report when a required dependency or decision is missing.

## Pre-Change Audit

Before modifying files, inspect the relevant files and summarize:

- current behavior;
- risks and dependencies;
- proposed files to modify;
- test plan;
- explicit non-goals.

## Test-Driven Changes

Behavioral implementation must follow:

```text
RED -> GREEN -> REFACTOR
```

Write or update tests before implementation, demonstrate the expected failing result, make the smallest passing change and refactor only after the tests pass. Do not delete, weaken or bypass a valid test just to obtain a passing result. Documentation, configuration and architecture changes should receive automated policy or validation tests where reasonably possible.

## Legacy Preservation

- `master` preserves the EvolvePHP 1 legacy line.
- EvolvePHP 1 maintenance must be clearly requested.
- An approved legacy-maintenance task must start from `master` and continue on a task-specific branch.
- Do not modernize EvolvePHP 1 unless the task explicitly targets legacy maintenance.
- Preserve historical evidence and document limitations clearly.
- EvolvePHP 2 development is based on the current `2.x` branch.
- EvolvePHP 2 changes must never be merged into `master`.
- EvolvePHP 1 changes must not be mixed into `2.x`.

## Branch Safety

- Do not work directly on `master`; direct work on `master` is prohibited unless the task explicitly targets approved legacy maintenance, and even then it must continue on a task-specific branch.
- Confirm a clean working tree before editing.
- Verify the requested base branch and exact base SHA before editing.
- Contributors and automation tools must not infer the correct base branch from GitHub's current default branch.
- Use a task-specific branch.
- Do not push, merge, tag or open a pull request unless explicitly requested; publication also requires explicit approval.
- Never rewrite shared history.
- Report unrelated pre-existing changes instead of overwriting them.

## Public APIs and Architecture

- Public contracts require deliberate review.
- Breaking changes require an RFC or explicit approval.
- Prefer composition over inheritance.
- Prefer dependency injection over service location.
- Prefer explicit behavior over hidden magic.
- Respect module ownership and dependency boundaries.
- Infrastructure implementations must remain replaceable through contracts.
- Core must not depend on optional outward packages.
- Production packages must not depend on Testing or DevTools.
- Package dependencies are declared through Composer only.
- Bridge remains outside Core.

## Database Migrations

- Do not modify an already released or applied migration.
- When a migration needs correction, create a new repair or follow-up migration.
- Migration changes require tests and rollback consideration.

## Security

- Do not expose secrets, credentials, tokens or private information.
- Do not weaken validation, authorization, CSRF, CORS or output escaping.
- Security-sensitive changes require negative-path tests.
- Document discovered security risks even when remediation is out of scope.

## Dependencies

- Do not add, remove or upgrade dependencies without explaining the need.
- Prefer existing standards and packages over custom implementations.
- Dependency changes require compatibility and license review.
- Do not modernize legacy dependencies during preservation-only work.

## Observability and Performance

- Do not make performance claims without reproducible measurements.
- Add telemetry to important framework lifecycle operations only when required by accepted design.
- Avoid logging secrets or sensitive payloads.
- Persistent-worker changes must test state reset and memory behavior.

## Documentation

- Documentation must match implemented behavior.
- Do not claim features, compatibility, security or performance without evidence.
- Include commands that were actually executed.
- Clearly identify historical claims, maintainer-reported facts and independently verified facts.
- Keep public documentation tool-neutral and durable.

## Completion Evidence

Every final agent report must include the same contribution evidence expected from any automated repository work:

1. Audit summary
2. Files created
3. Files modified
4. Tests added
5. Commands executed
6. Test results
7. Static-analysis or validation results
8. Remaining risks
9. Deferred work
10. Confirmation that no out-of-scope files were changed
