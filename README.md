# EvolvePHP 2

`2.x` is the designated EvolvePHP 2 development branch and the GitHub default branch.

`master` remains the preserved EvolvePHP 1 legacy branch for historical reference and explicitly approved legacy maintenance. EvolvePHP 2 development and proposed changes must not target `master`.

EvolvePHP 2 is a separate redesign, not an in-place refactor, replacement or rewrite of the EvolvePHP 1 runtime history.

Phase 4.7 promoted the EvolvePHP 2 Composer, PHPUnit, PHPStan, PHP-CS-Fixer, Deptrac, release-validation and supply-chain development root from `workspace/` to the repository root. The root project is not a release package. That root cutover does not delete `master`, does not delete history and does not change the preserved EvolvePHP 1 line.

## Project Overview

EvolvePHP is a modernization-first PHP framework project for building modular applications and evolving existing PHP systems without a full rewrite.

The current EvolvePHP 2 repository contains package boundaries, root Composer setup and root quality-tooling foundations. EvolvePHP 2 is under development and not production-ready. Runtime framework implementation is not yet complete, framework development is pre-release, and the packages are not yet published.

EvolvePHP 2 requires PHP 8.4. The current root quality pipeline is verified by GitHub Actions on PHP 8.4 and PHP 8.5 for the current root, tooling and package foundation.

## Current Status

- EvolvePHP 2 development branch: `2.x`
- GitHub default branch: `2.x`
- Legacy line: EvolvePHP 1 on `master`
- Required `2.x` checks: `Policy (PHP 8.4)`, `Workspace quality (PHP 8.4)`, `Workspace quality (PHP 8.5)`
- Runtime implementation: not yet complete
- Release status: pre-release, not production-ready
- Package publication: packages are not yet independently published
- PHP baseline: PHP 8.4
- CI verification: current root quality passes in GitHub Actions on PHP 8.4 and PHP 8.5

`Workspace quality` remains in protected CI context names for governance compatibility. It no longer means a tracked `workspace/` directory exists on `2.x`.

## Branch Governance

Phase 2.7B completed the external governance transition without replacing history. Repository rulesets actively protect both branch lines: `master` remains preserved, and `2.x` receives default-branch development changes.

The `master` ruleset requires pull requests, blocks deletion, blocks force-pushes, keeps required approvals at zero, requires conversation resolution and has no bypass actors.

The `2.x` ruleset requires pull requests, blocks deletion, blocks force-pushes, enforces required CI status checks with strict up-to-date status-check policy, keeps required approvals at zero, requires conversation resolution and has no bypass actors.

`master` has not been renamed or deleted, no branch was renamed or deleted, no `main` branch has been created, and no `1.x` branch has been created. Any stable-release branch rename or promotion remains deferred. Phase 2.7 does not replace the legacy history.

## Requirements

- PHP 8.4
- Composer
- Git

The preserved EvolvePHP 1 line has different historical requirements. Use `master` only when intentionally reviewing or maintaining EvolvePHP 1.

## Repository Layout

```text
evolvephp/
|-- docs/history/    # Preserved EvolvePHP 1 historical documentation
|-- docs/rfcs/       # Accepted EvolvePHP 2 RFCs and governance index
|-- packages/        # Six EvolvePHP 2 package boundaries
|-- tests/           # Architecture and documentation policy tests
|-- tools/           # Root release and supply-chain validation tools
|-- composer.json    # EvolvePHP 2 root development manifest
|-- composer.lock    # Reproducible root development lockfile
|-- DEVELOPMENT.md   # Canonical development, quality and release commands
`-- README.md        # EvolvePHP 2 branch entry point
```

The EvolvePHP 1 runtime files are not present in the `2.x` working tree after Phase 4.7. They remain preserved on `master` and in Git history.

## Getting Started

```bash
git clone https://github.com/josiahking/evolvephp.git
cd evolvephp
git branch --show-current
composer install
composer quality
```

The normal clone path starts on `2.x` because it is the GitHub default branch. For an older local checkout, verify the branch with `git branch --show-current` and run `git switch 2.x` only when the checkout is not already on `2.x`.

Use [DEVELOPMENT.md](DEVELOPMENT.md) for detailed Composer, PHPUnit, PHPStan, PHP-CS-Fixer, Deptrac, supply-chain, release-validation and developer-experience commands.

## Package Architecture

The current EvolvePHP 2 package set is documented in [packages/README.md](packages/README.md).

The package architecture uses explicit namespace ownership and inward dependency direction. Runtime contracts and framework behavior are added through approved implementation phases.

## EvolvePHP 1 History

EvolvePHP 1 has been used as the foundation of Africa Global Export Market, a live business-to-business export platform serving more than 5,000 users.

That production-use background belongs to the preserved EvolvePHP 1 history. New EvolvePHP 2 work happens separately on `2.x`, and EvolvePHP 2 changes must not target `master`. New production applications should not start from EvolvePHP 1 without a detailed security and compatibility review.

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
