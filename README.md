# EvolvePHP 2

`2.x` is the designated EvolvePHP 2 development branch and the GitHub default branch.

`master` remains the preserved EvolvePHP 1 legacy branch for historical reference and explicitly approved legacy maintenance. EvolvePHP 2 development and proposed changes must not target `master`.

Phase 2.7B completed the external governance transition. Repository rulesets actively protect both branch lines: `master` remains preserved, and `2.x` now receives default-branch development changes.

EvolvePHP 2 is not an in-place refactor, replacement or rewrite of the EvolvePHP 1 runtime history.

The current EvolvePHP 2 repository contains package boundaries, Composer workspace setup and quality-tooling foundations. Runtime framework implementation is not yet complete, and the packages are not yet published.

EvolvePHP 2 requires PHP 8.4. The current workspace quality pipeline is verified by GitHub Actions on PHP 8.4 and PHP 8.5 for the current workspace, quality tooling and package foundation.

## Project Overview

EvolvePHP is a lightweight, component-based PHP framework project for building structured web applications.

The EvolvePHP 2 line redesigns the framework as a modular package architecture with explicit contracts, dependency boundaries and workspace-owned development tooling. The current focus is the foundation for later runtime implementation, not production framework usage.

## Current Status

- EvolvePHP 2 development branch: `2.x`
- GitHub default branch: `2.x`
- Legacy line: EvolvePHP 1 on `master`
- Repository rulesets: active for `master` and `2.x`
- `master` ruleset: pull request requirement, deletion protection, force-push protection, required approvals remain zero, conversation resolution is required, and there are no bypass actors
- `2.x` ruleset: pull request requirement, deletion protection, force-push protection, required CI status checks, strict/up-to-date status-check policy, required approvals remain zero, conversation resolution is required, and there are no bypass actors
- Required `2.x` checks: `Policy (PHP 8.4)`, `Workspace quality (PHP 8.4)`, `Workspace quality (PHP 8.5)`
- Runtime implementation: not yet complete
- Package publication: packages are not yet published
- PHP baseline: PHP 8.4
- CI verification: current workspace quality passes in GitHub Actions on PHP 8.4 and PHP 8.5

## Requirements

- PHP 8.4
- Composer
- Git

The preserved EvolvePHP 1 line has different historical requirements. Use `master` only when intentionally reviewing or maintaining EvolvePHP 1.

## Repository Layout

```text
evolvephp/
|-- docs/rfcs/       # Accepted EvolvePHP 2 RFCs and governance index
|-- packages/        # EvolvePHP 2 package skeletons and package documentation
|-- tests/           # Root policy, architecture and documentation tests
|-- workspace/       # EvolvePHP 2 Composer workspace and quality tooling
|-- composer.json    # Preserved EvolvePHP 1 root Composer manifest
`-- README.md        # EvolvePHP 2 branch entry point
```

The legacy EvolvePHP 1 runtime files remain in the repository for preservation and maintenance history. They are not the EvolvePHP 2 package structure.

## Getting Started

```bash
git clone https://github.com/josiahking/evolvephp.git
cd evolvephp
git branch --show-current
composer --working-dir=workspace install
composer --working-dir=workspace quality
```

The normal clone path starts on `2.x` because it is the GitHub default branch. For an older local checkout, verify the branch with `git branch --show-current` and run `git switch 2.x` only when the checkout is not already on `2.x`.

The EvolvePHP 2 workspace owns dependency installation and quality checks. Use [workspace/README.md](workspace/README.md) for detailed Composer, PHPUnit, PHPStan, PHP-CS-Fixer and Deptrac guidance.

## Developer Experience

VS Code support is optional developer tooling for contributors. It is not framework runtime configuration, and it does not replace terminal PHP or Composer execution.

The repository includes a portable `.editorconfig` foundation and committed VS Code recommendations, settings and tasks. No machine-specific executable path is committed. Contributors with multiple PHP versions must make PHP 8.4+ available to their VS Code integrated terminal and local environment before relying on the tasks.

The VS Code tasks are convenience wrappers around the canonical Composer workspace scripts owned by `workspace/composer.json`. `EvolvePHP 2: Quality`, `EvolvePHP 2: Tests`, `EvolvePHP 2: Architecture`, `EvolvePHP 2: Static Analysis`, `EvolvePHP 2: Style Check` and `EvolvePHP 2: Root Policy` are normal non-mutating checks. `EvolvePHP 2: Install Workspace Dependencies` installs local dependency content. `EvolvePHP 2: Style Fix` is explicitly mutating.

Runtime debugging configuration is deferred until the EvolvePHP 2 runtime implementation is complete.

## Quality Checks

The main EvolvePHP 2 validation command is:

```bash
composer --working-dir=workspace quality
```

Root documentation and architecture policy tests continue to run from the repository root while the EvolvePHP 2 workspace matures.

## Package Architecture

The current EvolvePHP 2 package set is documented in [packages/README.md](packages/README.md).

The package architecture uses explicit namespace ownership and inward dependency direction. Runtime contracts and framework behavior will be added through later implementation phases.

## EvolvePHP 1 History

EvolvePHP 1 has been used as the foundation of Africa Global Export Market, a live business-to-business export platform serving more than 5,000 users.

That production-use background belongs to the preserved EvolvePHP 1 history. New EvolvePHP 2 work happens separately on `2.x`, and EvolvePHP 2 changes must not target `master`. New production applications should not start from EvolvePHP 1 without a detailed security and compatibility review.

Phase 2.7B completed the external governance transition without replacing history. `master` has not been renamed or deleted, no branch was renamed or deleted, no `main` branch has been created, and no `1.x` branch has been created. Any stable-release branch rename or promotion is deferred, and Phase 2.7 does not replace the legacy history.

See the [legacy overview](docs/history/evolvephp-1-overview.md), [known risks and limitations](docs/history/known-risks-and-limitations.md), [support policy](SUPPORT.md) and [security policy](SECURITY.md).

## Roadmap And RFCs

The authoritative RFC index is [docs/rfcs/README.md](docs/rfcs/README.md).

[RFC 0001](docs/rfcs/0001-evolvephp-2-vision-and-scope.md) defines the accepted EvolvePHP 2 vision, scope and non-goals.

## Author

**Josiah Gerald**

Senior Backend Engineer specializing in PHP, Laravel, REST APIs, payment integrations and production business platforms.

- GitHub: [github.com/josiahking](https://github.com/josiahking)
- LinkedIn: [linkedin.com/in/josiah-g-0919763b](https://www.linkedin.com/in/josiah-g-0919763b/)

## License

EvolvePHP is available under the BSD 3-Clause License.
