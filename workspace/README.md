# EvolvePHP 2 Composer Workspace

`workspace/` is the dedicated Composer development root for the EvolvePHP 2 modular monorepo.

The workspace resolves local packages, owns development tooling and runs EvolvePHP 2 package quality checks. It is not a publishable framework package, an application skeleton, runtime framework code, a Composer plugin or a production deployment artifact.

The preserved EvolvePHP 1 root Composer harness remains separate so legacy documentation and repository policy tests can continue to run from the repository root.

## Requirements

- PHP 8.4
- Composer
- Git

EvolvePHP 2 requires PHP 8.4. Workspace tooling and tests have been executed locally under PHP 8.4. PHP 8.5 compatibility remains pending the Phase 2.6 CI matrix.

Platform emulation must not be used for runtime compatibility claims. Do not use `config.platform.php`, `--ignore-platform-req=php` or `--ignore-platform-reqs` to generate the workspace lockfile or claim PHP compatibility.

## Package Resolution

Packages are resolved from one Composer path repository:

```text
../packages/*
```

The workspace maps each initial package explicitly to `2.0.x-dev` inside the path repository:

- `evolvephp/contracts`
- `evolvephp/core`
- `evolvephp/http`
- `evolvephp/module`
- `evolvephp/plugin`
- `evolvephp/testing`

The path repository is the authoritative local package source. The workspace consumes those packages through bounded `^2.0@dev` constraints without adding project-wide `minimum-stability` or `prefer-stable` settings.

Production packages are listed in `require`. Testing support and quality tooling are listed in `require-dev`.

Production packages remain independent of `evolvephp/testing`.

## First-Time Dependency Installation

Normal checkout setup uses the committed lockfile:

```bash
composer --working-dir=workspace install
```

Use `install` for ordinary local setup and validation. It installs the dependency versions already resolved in `workspace/composer.lock`.

## Intentional Dependency Updates

Use `update` only for deliberate dependency changes:

```bash
composer --working-dir=workspace update
```

`update` changes dependency resolution and the committed lockfile. It is not the normal setup command.

Do not run `update` for documentation-only work unless a task explicitly approves dependency changes.

## Local Commands

Validate the workspace Composer schema and lockfile:

```bash
composer --working-dir=workspace validate --strict --check-lock
```

Run the complete EvolvePHP 2 workspace PHPUnit suite:

```bash
composer --working-dir=workspace test
```

Run individual package suites:

```bash
composer --working-dir=workspace test:contracts
composer --working-dir=workspace test:core
composer --working-dir=workspace test:http
composer --working-dir=workspace test:module
composer --working-dir=workspace test:plugin
composer --working-dir=workspace test:testing
```

Run static analysis:

```bash
composer --working-dir=workspace analyse
```

Run architecture and dependency-boundary validation:

```bash
composer --working-dir=workspace architecture
```

Run coding-standard checks:

```bash
composer --working-dir=workspace style:check
```

Apply coding-standard fixes:

```bash
composer --working-dir=workspace style:fix
```

Run non-mutating workspace quality validation:

```bash
composer --working-dir=workspace quality
```

`quality` runs `architecture`, `analyse`, `style:check` and `test`, in that order. `style:fix` remains separate because it is mutating.

## PHPUnit Suites

PHPUnit 13 is owned by the EvolvePHP 2 workspace. It must not be added to the legacy root Composer manifest, any package manifest or production requirements.

The workspace PHPUnit configuration lives at:

```text
workspace/phpunit.xml.dist
```

It bootstraps through `workspace/vendor/autoload.php` and defines one named suite for each initial package:

| Suite | Test directory |
| --- | --- |
| `contracts` | `packages/contracts/tests` |
| `core` | `packages/core/tests` |
| `http` | `packages/http/tests` |
| `module` | `packages/module/tests` |
| `plugin` | `packages/plugin/tests` |
| `testing` | `packages/testing/tests` |

The legacy root suite and the EvolvePHP 2 workspace suite are separate harnesses. The legacy root suite preserves and validates repository policy while the workspace suite runs package tests under the EvolvePHP 2 PHP baseline.

## Static Analysis

PHPStan is the primary static analyzer for the EvolvePHP 2 workspace. PHPStan and PHP-CS-Fixer are workspace-only development tools. They must not be added to the legacy root Composer manifest, any package manifest or production requirements.

The distributable PHPStan configuration lives at:

```text
workspace/phpstan.neon.dist
```

The initial PHPStan level is `6`. PHPStan analyzes all six package `src` and `tests` directories:

```text
../packages/contracts/src
../packages/contracts/tests
../packages/core/src
../packages/core/tests
../packages/http/src
../packages/http/tests
../packages/module/src
../packages/module/tests
../packages/plugin/src
../packages/plugin/tests
../packages/testing/src
../packages/testing/tests
```

The workspace manually includes `phpstan/phpstan-phpunit` type-inference integration through `vendor/phpstan/phpstan-phpunit/extension.neon`.

No PHPStan baseline is allowed, and the configuration must not use `ignoreErrors`. Local PHPStan cache belongs in `workspace/.phpstan-cache/` and is ignored.

## Architecture Boundaries

Deptrac is workspace-owned architecture-boundary tooling. The maintained package identity is `deptrac/deptrac`; the abandoned `qossmic/deptrac` package identity is prohibited.

The configuration lives at:

```text
workspace/deptrac.php
```

Deptrac analyzes production source directories only:

```text
../packages/contracts/src
../packages/core/src
../packages/http/src
../packages/module/src
../packages/plugin/src
../packages/testing/src
```

Package tests are excluded from Deptrac boundary analysis so test dependencies cannot weaken production rules. Physical package paths define layers, and package namespaces must match the package paths:

```text
Contracts -> ../packages/contracts/src/.* -> Evolve\Contracts\
Core      -> ../packages/core/src/.*      -> Evolve\Core\
Http      -> ../packages/http/src/.*      -> Evolve\Http\
Module    -> ../packages/module/src/.*    -> Evolve\Module\
Plugin    -> ../packages/plugin/src/.*    -> Evolve\Plugin\
Testing   -> ../packages/testing/src/.*   -> Evolve\Testing\
```

The accepted dependency matrix is:

```text
Contracts -> none
Core      -> Contracts
Http      -> Contracts, Core
Module    -> Contracts
Plugin    -> Contracts
Testing   -> Contracts, Core, Http, Module, Plugin
```

There is no production dependency on Testing. Testing may depend on all five production packages.

Uncovered dependencies fail. No baseline or skipped violations are allowed. No graph is generated. New external dependency treatment requires deliberate architecture review.

## Coding Standards

PHP-CS-Fixer is the workspace coding-standard engine. The distributable configuration lives at:

```text
workspace/.php-cs-fixer.dist.php
```

The project style is based on PHP-FIG PER Coding Style 3.0 through PHP-CS-Fixer's `@PER-CS3x0` rule set. The floating `@PER-CS` alias is not used. The project explicitly enables alphabetical `ordered_imports` and `no_unused_imports`.

PHP-CS-Fixer checks only the six package `src` and `tests` directories. The preserved EvolvePHP 1 root files, root architecture tests, root documentation tests, RFCs, `workspace/vendor/` and generated caches are excluded.

Risky rules are disabled. The `declare_strict_types` fixer is not enabled; strict-types policy for EvolvePHP 2 package PHP files is enforced by architecture tests.

## Lockfile Policy

`workspace/composer.lock` is committed for reproducible development and CI resolution.

The lockfile must be generated and updated through Composer under real PHP 8.4 execution. It must never be handwritten or generated with platform emulation.

## Compatibility Evidence

EvolvePHP 2 requires PHP 8.4. Local workspace validation has been performed under PHP 8.4.

PHP 8.5 compatibility remains pending the Phase 2.6 CI matrix. Do not claim PHP 8.5 support until that evidence exists.

## Deferred Work

The following work remains deferred:

- GitHub Actions
- PHP 8.5 CI verification
- Security and license scanning
- Release automation
- Runtime framework implementation
