# Contributing

Thank you for helping improve EvolvePHP.

EvolvePHP 2 contributions target the current `2.x` line through task-specific branches. Do not work directly on `2.x` or `master`. The `master` branch preserves the EvolvePHP 1 legacy line for historical reference and explicitly approved legacy maintenance.

## Reporting Bugs And Proposing Changes

Use public issues or discussions for usage questions, bug reports and proposed changes when the report does not contain sensitive information. Include enough detail for maintainers to reproduce the behavior: commit or branch, PHP version, Composer status, operating system, relevant command, expected result and actual result.

Security vulnerabilities must be reported through [SECURITY.md](SECURITY.md) rather than public issues or discussions.

## Contribution Scope

Keep changes small, reviewable and limited to the requested problem. Identify assumptions, avoid adjacent feature work and preserve unrelated files. Documentation should describe implemented behavior and current limitations rather than future promises.

Behavioral changes are expected to follow `RED -> GREEN -> REFACTOR`: add or update the failing test first, make the smallest passing change and refactor only after the behavior is covered. Documentation, configuration and architecture changes should have policy or validation tests where practical.

Run the relevant focused checks first, then broader quality checks when the change is ready. Detailed setup, Composer commands and validation guidance live in [DEVELOPMENT.md](DEVELOPMENT.md).

## Architecture And Public API

Public API and architecture changes require deliberate review. Accepted RFCs must not be silently contradicted. Package ownership, dependency direction and Composer dependency boundaries must be respected.

Do not add, remove or upgrade dependencies without explaining the need and accounting for compatibility and licence impact.

## Pull Requests

Pull requests should describe the scope, tests run, remaining risks and deferred work. Keep review evidence factual and avoid claiming production readiness, package publication or compatibility that the repository does not prove.

Automation tools and coding assistants working in this repository must also follow [AGENTS.md](AGENTS.md). EvolvePHP is licensed under the BSD 3-Clause License; see [LICENSE.md](LICENSE.md).
