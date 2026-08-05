# EvolvePHP 2

This branch contains the EvolvePHP 2 redesign on `2.x`.

EvolvePHP 1 remains preserved on `master` for historical reference and legacy maintenance. EvolvePHP 2 is not an in-place refactor of the EvolvePHP 1 runtime.

The current EvolvePHP 2 repository contains package boundaries, Composer workspace setup and quality-tooling foundations. Runtime framework implementation is not yet complete, and the packages are not yet published.

EvolvePHP 2 requires PHP 8.4. Local PHP 8.4 validation has been performed. PHP 8.5 remains pending Phase 2.6 CI evidence.

## Project Overview

EvolvePHP is a lightweight, component-based PHP framework project for building structured web applications.

The EvolvePHP 2 line redesigns the framework as a modular package architecture with explicit contracts, dependency boundaries and workspace-owned development tooling. The current focus is the foundation for later runtime implementation, not production framework usage.

## Current Status

- Branch: `2.x`
- Legacy line: EvolvePHP 1 on `master`
- Runtime implementation: not yet complete
- Package publication: packages are not yet published
- PHP baseline: PHP 8.4
- PHP 8.5: compatibility remains pending Phase 2.6 CI evidence

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
git switch 2.x
composer --working-dir=workspace install
composer --working-dir=workspace quality
```

The EvolvePHP 2 workspace owns dependency installation and quality checks. Use [workspace/README.md](workspace/README.md) for detailed Composer, PHPUnit, PHPStan, PHP-CS-Fixer and Deptrac guidance.

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

That production-use background belongs to the preserved EvolvePHP 1 history. New EvolvePHP 2 work happens separately on `2.x`, and new production applications should not start from EvolvePHP 1 without a detailed security and compatibility review.

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
