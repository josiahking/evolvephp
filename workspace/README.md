# EvolvePHP 2 Composer Workspace

`workspace/` is the dedicated EvolvePHP 2 Composer development root for the modular monorepo.

The legacy root Composer harness remains separate temporarily so the preserved EvolvePHP 1 repository and current architecture and documentation tests can continue to run from the repository root.

This workspace is not a publishable framework package, a replacement for `evolvephp/framework`, an application skeleton, runtime framework code, a Composer plugin or a production deployment artifact.

## Package Resolution

Packages are resolved from one Composer path repository:

```text
../packages/*
```

Every Phase 2.1 package is mapped explicitly to `2.0.x-dev` inside the workspace path repository. The path repository remains the authoritative local package source. This avoids task-branch version ambiguity and lets local package manifests retain their normal `^2.0` dependency constraints without temporary package-level `version` fields.

The workspace consumes those local packages through bounded `^2.0@dev` dependency constraints. The `@dev` stability flag permits the mapped development packages without adding project-wide `minimum-stability` or `prefer-stable` settings. Composer's solver output should still resolve all six packages as `2.0.x-dev`.

Production packages are listed in `require`:

- `evolvephp/contracts`
- `evolvephp/core`
- `evolvephp/http`
- `evolvephp/module`
- `evolvephp/plugin`

Testing support is listed in `require-dev`:

- `evolvephp/testing`
- `phpunit/phpunit`

Quality tooling is also listed in `require-dev` and is owned only by this workspace:

- `phpstan/phpstan`
- `phpstan/phpstan-phpunit`
- `friendsofphp/php-cs-fixer`
- `deptrac/deptrac`

Production packages remain independent of `evolvephp/testing`.

PHPUnit 13 is owned by the EvolvePHP 2 workspace. It must not be added to the legacy root Composer manifest, any package manifest or production requirements.

PHPStan and PHP-CS-Fixer are also workspace-only development tools. They must not be added to the legacy root Composer manifest, any package manifest or production requirements.

Deptrac is also workspace-owned development tooling. The maintained package identity is `deptrac/deptrac`; the abandoned `qossmic/deptrac` package identity is prohibited.

## Local Commands

Validate the workspace Composer schema:

```bash
composer --working-dir=workspace validate --strict
```

Install or update workspace dependencies only through real Composer execution on PHP 8.4:

```bash
composer --working-dir=workspace update \
  --no-interaction \
  --no-progress \
  --no-audit
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

`architecture` is non-mutating. It analyzes production source directories only, reports uncovered dependencies, and uncovered dependencies fail. It uses no baseline or skipped violations, and does not generate a graph.

Run coding-standard checks:

```bash
composer --working-dir=workspace style:check
```

Apply coding-standard fixes:

```bash
composer --working-dir=workspace style:fix
```

`style:fix` is mutating and is intentionally separate from validation commands.

Run non-mutating workspace quality validation:

```bash
composer --working-dir=workspace quality
```

`quality` calls `architecture`, `analyse`, `style:check` and `test`, in that order. Architecture runs first so package-boundary violations stop the pipeline before later quality checks. It does not call `style:fix`.

Workspace Composer scripts use Composer's `@php` so vendor tools run under the PHP binary selected by the Composer execution environment. On the PHP 8.4 workspace, Composer84 uses `D:\php-84\php.exe`.

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

The legacy root suite and the EvolvePHP 2 workspace suite are separate harnesses. The legacy root suite continues to preserve and validate repository policy while the workspace suite runs package tests under the EvolvePHP 2 PHP baseline.

## Static Analysis

PHPStan is the primary static analyzer for the EvolvePHP 2 workspace. The distributable configuration lives at:

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

The workspace manually includes `phpstan/phpstan-phpunit` type-inference integration through `vendor/phpstan/phpstan-phpunit/extension.neon`. The optional strict PHPUnit rules are not enabled in Phase 2.4.

No PHPStan baseline is allowed initially, and the configuration must not use `ignoreErrors`. Local PHPStan cache belongs in `workspace/.phpstan-cache/` and is ignored.

## Architecture Boundaries

Deptrac is the Phase 2.5 architecture-boundary engine. The configuration lives at:

```text
workspace/deptrac.php
```

Phase 2.5 analyzes the six package production source directories only:

```text
../packages/contracts/src
../packages/core/src
../packages/http/src
../packages/module/src
../packages/plugin/src
../packages/testing/src
```

Package tests are excluded from Phase 2.5 boundary analysis so test dependencies cannot weaken production rules. Physical package paths define layers, and package namespaces must match the package paths:

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

New external dependencies require explicit architecture review instead of being silently accepted. No architecture baseline, skipped violations or generated graph is allowed in Phase 2.5. Local Deptrac cache belongs in `workspace/.deptrac.cache` and is ignored. The temporary forbidden-edge probe used during Phase 2.5 validation is evidence only and is not committed.

## Coding Standards

PHP-CS-Fixer is the workspace coding-standard engine. The distributable configuration lives at:

```text
workspace/.php-cs-fixer.dist.php
```

The project style is based on PHP-FIG PER Coding Style 3.0 through PHP-CS-Fixer's `@PER-CS3x0` rule set. The floating `@PER-CS` alias is not used. The project explicitly enables alphabetical `ordered_imports` and `no_unused_imports`.

PHP-CS-Fixer checks only the six package `src` and `tests` directories. The preserved EvolvePHP 1 root files, root architecture tests, root documentation tests, RFCs, `workspace/vendor/` and generated caches are excluded.

Risky rules are disabled. The `declare_strict_types` fixer is not enabled; strict-types policy for EvolvePHP 2 package PHP files is enforced by architecture tests.

Platform emulation must not be used for runtime compatibility claims. Do not use `config.platform.php`, `--ignore-platform-req=php` or `--ignore-platform-reqs` to generate the workspace lockfile or claim PHP compatibility.

## Lockfile

`workspace/composer.lock` is committed for reproducible development and CI resolution.

It was intentionally absent in Phase 2.2. Phase 2.3 creates the first workspace lockfile under real PHP 8.4 execution. It must never be handwritten or generated with `config.platform.php`.

Future changes to workspace dependencies must update the lockfile through Composer, never manually.

## Deferred Work

The following remain deferred:

- GitHub Actions
- PHP 8.5 CI verification to Phase 2.6
- Security and licence scanning
- Release automation
- Framework implementation
