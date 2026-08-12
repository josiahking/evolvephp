# EvolvePHP 2 Composer Workspace

`workspace/` is the dedicated Composer development root for the EvolvePHP 2 modular monorepo.

The workspace resolves local packages, owns development tooling and runs EvolvePHP 2 package quality checks. It is not a publishable framework package, an application skeleton, runtime framework code, a Composer plugin or a production deployment artifact.

The preserved EvolvePHP 1 root Composer harness remains separate so legacy documentation and repository policy tests can continue to run from the repository root.

## Requirements

- PHP 8.4
- Composer
- Git

EvolvePHP 2 requires PHP 8.4 as its baseline. GitHub Actions exercises the current workspace quality pipeline on PHP 8.4 and PHP 8.5 for the current tooling and package foundation.

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

## Release Validation

Run deterministic/offline package release-readiness validation:

```bash
composer --working-dir=workspace release:validate
```

Phase 2.10A keeps the six packages mapped explicitly in `workspace/release-packages.json`. The processing order is dependency-compatible: contracts, core, module, plugin, http and testing. Package-local README and licence files exist so future split roots carry consumer documentation and legal text naturally. Package licences must remain identical to root `LICENSE.md`.

No package is being published by this command. No remote repositories are contacted, no tags/releases are created, and no split repositories are synchronized. Package Composer manifests remain authoritative for package metadata.

`release:validate` is distinct from `quality`. It is also distinct from network-dependent `supply-chain`. Package splitting is Phase 2.10B validation work, and prerelease consumer stability is validated by the Phase 2.10B consumer matrix. RFC 0003 remains authoritative for release and version policy.

### Package Split Validation

Run deterministic package split validation:

```bash
composer --working-dir=workspace release:split:validate
```

`release:split:validate` reads `workspace/release-packages.json`, creates a disposable local clone with `--no-hardlinks`, runs every mapped `git subtree` split twice, validates repeated split SHA equality, validates exact subtree/root tree equality, validates exact inventory equality, validates generated split-root Composer manifests, and confirms package-specific Git history is retained. It creates no remote repository, pushes nothing, creates no source tags, works only on committed Git history/ref and runs in Policy PHP 8.4 CI.

### Prerelease Consumer Validation

Run offline prerelease and stable consumer validation:

```bash
composer --working-dir=workspace release:consumer:validate
```

`release:consumer:validate` creates temporary local VCS package repositories from generated split roots, creates disposable alpha/stable tags only, disables Packagist, disables Composer network access, validates expected success and expected failure cases, and uses disposable lockfiles. It is not currently a required CI step and is intended for pre-release/manual validation.

For an EvolvePHP 2 alpha consumer, the recommended root consumer settings are:

```json
{
    "minimum-stability": "alpha",
    "prefer-stable": true
}
```

These are root consumer settings. First-party package manifests must not add alpha stability policy. Existing first-party internal constraints remain `^2.0`; no package Composer manifest should add `@alpha`, `minimum-stability`, `prefer-stable` or a hard-coded `version` field. Top-level `@alpha` on only one package is insufficient for transitive alpha packages. Explicit root `@alpha` flags for every involved EvolvePHP package are a valid but more verbose alternative. Stable 2.0.0 consumers do not require alpha stability settings.

In prose: set `minimum-stability: alpha` and `prefer-stable: true` only in the root alpha consumer.

No package is published by `release:validate`, `release:split:validate` or `release:consumer:validate`. Remote package repositories, remote synchronization, Packagist registration, tags and releases remain deferred.

`release:validate` remains metadata/package-boundary validation. `release:split:validate` validates generated split history/root content. `release:consumer:validate` validates package-resolution semantics. `supply-chain` remains network-dependent security/licence validation. `quality` remains ordinary workspace quality.

## Supply-Chain Security

Run the Composer lockfile security audit:

```bash
composer --working-dir=workspace security:audit
```

`security:audit` checks the committed `workspace/composer.lock` dependency set through Composer audit, keeps `require-dev` included intentionally and fails on abandoned packages. Vulnerability, malware and dependency-policy findings must not be hidden by advisory suppression without an explicit future security decision. There is no advisory suppression list in this foundation.

Run the locked dependency licence-policy check:

```bash
composer --working-dir=workspace licenses:check
```

`licenses:check` validates locked production and development packages from both `packages` and `packages-dev`. The approved locked dependency licence identifiers are:

- MIT
- BSD-3-Clause
- Apache-2.0

This allowlist reflects reviewed dependencies in the committed lockfile. `Apache-2.0` was deliberately reviewed because `jetbrains/phpstorm-stubs` currently appears in `packages-dev`. The allowlist is a repository engineering dependency-admission policy, not legal advice or a general legal compatibility statement. New or unknown licence identifiers fail closed and require deliberate review before acceptance. Approval here does not remove distribution or attribution obligations that may apply if future release artifacts redistribute third-party material.

Run the aggregate supply-chain gate:

```bash
composer --working-dir=workspace supply-chain
```

`supply-chain` runs `security:audit` and then `licenses:check`. It is distinct from deterministic normal Composer quality tooling: `quality` remains the architecture, static-analysis, style and PHPUnit aggregate, while `supply-chain` may require network access for fresh remote advisory data.

CI executes the supply-chain gate through the required `Policy (PHP 8.4)` job after workspace dependencies are installed and before root policy tests. Dependabot version updates are repository-owned in `.github/dependabot.yml`: Composer updates watch `/workspace`, GitHub Actions updates watch `/`, and internal EvolvePHP path packages are excluded from Composer version-update pull requests.

Dependabot alerts and Dependabot security updates are GitHub settings. `.github/dependabot.yml` alone does not prove those GitHub settings are enabled.

## VS Code Developer Experience

VS Code is optional developer tooling, not framework runtime configuration. The repository root is the VS Code workspace; no separate `.code-workspace` file is required for this phase.

The committed recommendations are intentionally minimal: EditorConfig for shared editor whitespace policy and Intelephense for PHP 8.4 language-analysis support. The Intelephense setting targets PHP 8.4 syntax and symbols for editor feedback, but it does not replace actual PHP 8.4+ execution.

The VS Code tasks execute `php` and `composer` from the integrated terminal environment. Before relying on them, confirm that:

```bash
php --version
composer --version
```

show PHP 8.4+ and Composer. If a contributor has multiple PHP versions, they should configure their local OS, terminal or VS Code user environment so the integrated terminal resolves PHP 8.4+. Do not commit a machine-specific executable path.

`workspace/composer.json` remains the canonical source for Composer workspace scripts. `.vscode/tasks.json` is only a convenience interface, and CI remains the remote enforcement layer.

The VS Code tasks map to the canonical commands as follows:

| Task | Canonical command | Category |
| --- | --- | --- |
| `EvolvePHP 2: Install Workspace Dependencies` | `composer --working-dir=workspace install` | Dependency setup |
| `EvolvePHP 2: Quality` | `composer --working-dir=workspace quality` | Non-mutating |
| `EvolvePHP 2: Tests` | `composer --working-dir=workspace test` | Non-mutating |
| `EvolvePHP 2: Architecture` | `composer --working-dir=workspace architecture` | Non-mutating |
| `EvolvePHP 2: Static Analysis` | `composer --working-dir=workspace analyse` | Non-mutating |
| `EvolvePHP 2: Style Check` | `composer --working-dir=workspace style:check` | Non-mutating |
| `EvolvePHP 2: Style Fix` | `composer --working-dir=workspace style:fix` | Mutating |
| `EvolvePHP 2: Root Policy` | `php workspace/vendor/bin/phpunit --configuration phpunit.xml.dist tests/Architecture tests/Documentation` | Non-mutating |

Runtime debugging and Xdebug launch configuration are intentionally deferred because EvolvePHP 2 runtime implementation is not complete.

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

The workspace also models deliberate external standard layers:

```text
Core external standards
PsrContainer

Http external standards
PsrHttpMessage
PsrHttpServer
```

`PsrContainer` represents the approved PSR-11 interoperability layer used by Core. `PsrHttpMessage` and `PsrHttpServer` represent the approved PSR-7 message and PSR-15 server middleware/handler interface layers used by Http. These PSR HTTP interfaces are external interoperability standards and do not change the first-party Evolve package dependency direction: Http still depends inward on Contracts and Core, while the other first-party packages do not receive direct PSR HTTP access in Phase 4.1.

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

## Continuous Integration

The canonical GitHub Actions workflow lives at:

```text
.github/workflows/quality.yml
```

The workflow name is `EvolvePHP 2 Quality`.

It runs for pull requests targeting `2.x`, pushes to `2.x` and manual dispatch. It uses least-privilege `contents: read` permissions and cancels superseded executions through workflow concurrency.

All jobs run on the explicit Ubuntu 24.04 runner, using the `ubuntu-24.04` label. The workflow has no initial dependency cache.

The policy job runs on PHP 8.4. The `Policy (PHP 8.4)` job validates the workspace Composer manifest and lockfile before installation, installs workspace dependencies from the committed lockfile with `composer install`, and runs the root Architecture and Documentation policy tests through workspace PHPUnit 13:

```bash
php workspace/vendor/bin/phpunit --configuration phpunit.xml.dist tests/Architecture tests/Documentation
```

Those root policy tests validate EvolvePHP 2 repository governance and documentation. The preserved EvolvePHP 1 runtime is not part of the EvolvePHP 2 compatibility claim.

The workspace quality matrix runs PHP 8.4 and PHP 8.5. Each matrix entry validates Composer metadata before installation, installs from `workspace/composer.lock`, and runs:

```bash
composer --working-dir=workspace quality
```

The initial Phase 2.6 CI matrix has successfully executed. Workspace quality passes on PHP 8.4 and PHP 8.5, and the root policy job passes on PHP 8.4. This evidence applies to the current workspace, quality tooling and package foundation only.

PHP 8.5 evidence for the current workspace quality pipeline is recorded by the Phase 2.6 CI matrix.

The EvolvePHP 2 runtime implementation is incomplete, so this is not a broader runtime-production compatibility claim.

The workflow must not run `composer update`, use a platform-requirement bypass, install root Composer dependencies or replace the approved aggregate quality command with duplicated individual quality commands. There is no platform-requirement bypass in CI.

Action dependencies are pinned by immutable full-SHA references. The reviewed release comments must remain beside those SHAs so future audits can connect each commit pin to its intended release tag.

## Compatibility Evidence

EvolvePHP 2 requires PHP 8.4 as the baseline. The Phase 2.6 CI matrix has successfully executed in GitHub Actions: the current workspace quality pipeline passes on PHP 8.4 and PHP 8.5, and the root policy job passes on PHP 8.4.

This verifies the current workspace, quality tooling and package foundation only. The preserved EvolvePHP 1 runtime is excluded, and the EvolvePHP 2 runtime implementation remains incomplete.

## Deferred Work

The following work remains deferred:

- Security and license scanning
- Release automation
- Runtime framework implementation
