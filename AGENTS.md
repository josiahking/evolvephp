# Agent Workflow Rules

Every coding agent must read and follow this file before auditing, planning or modifying this repository.

## 1. Scope discipline

- Work only on the requested phase and task.
- Do not add adjacent features because they appear useful.
- Keep commits and changes small and reviewable.
- Identify assumptions before implementation.
- Stop and report when a required dependency or decision is missing.

## 2. Pre-implementation audit

Before code changes, every task must include:

- Relevant-file inspection
- Current-behaviour summary
- Risks and dependencies
- Proposed files to modify
- Test plan
- Explicit non-goals

## 3. Test-driven development

All behavioural implementation must use:

```text
RED -> GREEN -> REFACTOR
```

Rules:

- Write or update tests before implementation.
- Demonstrate that the test initially fails for the expected reason.
- Implement the smallest change that makes it pass.
- Refactor only after the tests pass.
- Run targeted tests and the relevant full suite.
- Never delete, weaken or bypass a valid test just to obtain a passing result.
- Documentation, configuration and architecture changes should receive automated policy or validation tests where reasonably possible.

## 4. Legacy preservation

- `master` represents the EvolvePHP 1 legacy line.
- Do not modernise EvolvePHP 1 unless a task explicitly targets legacy maintenance.
- Do not silently fix historical issues during documentation work.
- Preserve historical evidence and clearly document limitations.
- EvolvePHP 2 development must occur separately from the preserved legacy baseline.

## 5. Branch safety

- Do not work directly on `master`.
- Confirm a clean working tree before editing.
- Use a task-specific branch.
- Do not push, merge, tag or open a pull request unless explicitly requested.
- Never rewrite shared history.
- Report unrelated pre-existing changes instead of overwriting them.

## 6. Public APIs and architecture

- Public contracts require deliberate review.
- Breaking changes require an RFC or explicit approval.
- Prefer composition over inheritance.
- Prefer dependency injection over service location.
- Prefer explicit behaviour over hidden magic.
- Respect module ownership and dependency boundaries.
- Infrastructure implementations must remain replaceable through contracts.

## 7. Database migrations

- Do not modify an already released or applied migration.
- When a migration needs correction, create a new repair or follow-up migration.
- Migration changes require tests and rollback consideration.

## 8. Security

- Do not expose secrets, credentials, tokens or private information.
- Do not weaken validation, authorisation, CSRF, CORS or output escaping.
- Security-sensitive changes require negative-path tests.
- Never hide a discovered security risk merely to keep the task small; document it and keep unrelated remediation out of scope.

## 9. Dependencies

- Do not add, remove or upgrade dependencies without explaining the need.
- Prefer existing standards and packages over custom implementations.
- Dependency changes require compatibility and licence review.
- Do not modernise legacy dependencies during preservation-only tasks.

## 10. Observability and performance

- Do not make performance claims without reproducible measurements.
- Add telemetry to important framework lifecycle operations where required by the roadmap.
- Avoid logging secrets or sensitive payloads.
- Persistent-worker changes must test state reset and memory behaviour.

## 11. Documentation

- Documentation must match implemented behaviour.
- Do not claim features, compatibility, security or performance without evidence.
- Include commands that were actually executed.
- Clearly identify historical claims, maintainer-reported facts and independently verified facts.

## 12. Completion evidence

Every final agent report must include:

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

A statement such as "tests passed" without command and result evidence is insufficient.
