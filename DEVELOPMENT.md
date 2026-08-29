# EvolvePHP 2 Development

The repository root is the canonical EvolvePHP 2 Composer development root for the modular monorepo.

The root resolves local packages, owns development tooling and runs EvolvePHP 2 package quality checks. It is not a release package, a publishable framework package, an application skeleton, runtime framework code, a Composer plugin or a production deployment artifact.

The preserved EvolvePHP 1 runtime and the former legacy root suite remain preserved on `master` and in Git history. The EvolvePHP 2 root suite now runs from this repository root.

## Requirements

- PHP 8.4
- Composer
- Git

EvolvePHP 2 requires PHP 8.4 as its baseline. GitHub Actions exercises the current root quality pipeline on PHP 8.4 and PHP 8.5 for the current tooling and package foundation.

Platform emulation must not be used for runtime compatibility claims. Do not use `config.platform.php`, `--ignore-platform-req=php` or `--ignore-platform-reqs` to generate the root lockfile or claim PHP compatibility.

## Package Resolution

Packages are resolved from one Composer path repository:

```text
packages/*
```

The root maps each initial package explicitly to `2.0.x-dev` inside the path repository:

- `evolvephp/contracts`
- `evolvephp/core`
- `evolvephp/dev-tools`
- `evolvephp/http`
- `evolvephp/module`
- `evolvephp/plugin`
- `evolvephp/testing`

The path repository is the authoritative local package source. The root consumes those packages through bounded `^2.0@dev` constraints without adding project-wide `minimum-stability` or `prefer-stable` settings.

Production packages are listed in `require`. Testing support and quality tooling are listed in `require-dev`. Production packages remain independent of `evolvephp/testing`.

## First-Time Dependency Installation

Normal checkout setup uses the committed lockfile:

```bash
composer install
```

Use `install` for ordinary local setup and validation. It installs the dependency versions already resolved in `composer.lock`.

## Intentional Dependency Updates

Use `update` only for deliberate dependency changes:

```bash
composer update
```

`update` changes dependency resolution and the committed lockfile. It is not the normal setup command.

Do not run `update` for documentation-only work unless a task explicitly approves dependency changes.

## Local Commands

Validate the root Composer schema and lockfile:

```bash
composer validate --strict --check-lock
```

Run the complete EvolvePHP 2 root PHPUnit suite:

```bash
composer test
```

Run individual package suites:

```bash
composer test:contracts
composer test:core
composer test:dev-tools
composer test:http
composer test:module
composer test:plugin
composer test:testing
```

Run static analysis:

```bash
composer analyse
```

Run architecture and dependency-boundary validation:

```bash
composer architecture
```

Run coding-standard checks:

```bash
composer style:check
```

Apply coding-standard fixes:

```bash
composer style:fix
```

Run non-mutating root quality validation:

```bash
composer quality
```

`quality` runs `architecture`, `analyse`, `style:check` and `test`, in that order. `style:fix` remains separate because it is mutating.

## Application Skeleton

The `skeleton/` directory is the initial end-user application template for `evolvephp/skeleton`. It is separate from the framework monorepo root, and the root remains the EvolvePHP development workspace rather than an application project.

The skeleton uses `App\ => src/` as its application namespace convention. The application CLI composition is explicit: `skeleton/bin/evolve` loads the generated project's Composer autoloader, then uses the public experimental Core runtime APIs `CliApplication` and `StreamCommandOutput` with `CommandRegistry`, `CommandRunner`, `ServiceRegistry` and `ExecutionOrchestrator`. Core remains independent of HTTP, while the application-owned shell explicitly registers the Core Doctor command and the HTTP `route:list` adapter.

The initial route configuration is explicit and empty through `skeleton/config/routes.php`, which returns a `RouteCollection`. There is no route discovery, filesystem scanning, application boot magic or automatic command discovery in this skeleton slice. Doctor uses the current accepted runtime and Composer-extension checks only: `PhpVersionCheck` and `ComposerRequiredExtensionsCheck` against the generated application's own `composer.json`.

The skeleton installs `evolvephp/dev-tools`, `evolvephp/testing` and PHPUnit as development dependencies. `config/commands.php` registers `module:new` and `plugin:new` only when the DevTools classes exist, so a production `composer install --no-dev` keeps `doctor` and `route:list` available without requiring DevTools. The generator commands derive all output paths from the application root and a single ASCII StudlyCase name token; they do not edit Composer manifests, execute generated PHP, run Composer, run Git, auto-enable generated components or perform automatic discovery.

## Release Validation

Run deterministic/offline package release-readiness validation:

```bash
composer release:validate
```

Phase 2.10A keeps the release packages mapped explicitly in `release-packages.json`. Phase 6.4E extends that dependency-compatible map to seven packages by appending DevTools after the existing six-package order: contracts, core, module, plugin, http, testing and dev-tools. Package-local README and licence files exist so future split roots carry consumer documentation and legal text naturally. Package-local licences must remain identical to root `LICENSE.md`.

No package is being published by this command. No remote repositories are contacted, no tags/releases are created, and no split repositories are synchronized. Package Composer manifests remain authoritative for package metadata.

`release:validate` is distinct from `quality`. It is also distinct from network-dependent `supply-chain`. Package splitting is Phase 2.10B validation work, and prerelease consumer stability is validated by the Phase 2.10B consumer matrix. RFC 0003 remains authoritative for release and version policy.

### Package Split Validation

Run deterministic package split validation:

```bash
composer release:split:validate
```

`release:split:validate` reads `release-packages.json`, creates a disposable local clone with `--no-hardlinks`, runs every mapped `git subtree` split twice, validates repeated split SHA equality, validates exact subtree/root tree equality, validates exact inventory equality, validates generated split-root Composer manifests, and confirms package-specific Git history is retained. It creates no remote repository, pushes nothing, creates no source tags, works only on committed Git history/ref and runs in Policy PHP 8.4 CI.

### Prerelease Consumer Validation

Run offline prerelease and stable consumer validation:

```bash
composer release:consumer:validate
```

`release:consumer:validate` creates temporary local VCS package repositories from generated split roots, creates disposable alpha/stable tags only, disables Packagist, disables Composer network access, validates expected success and expected failure cases, and uses disposable lockfiles. It is not currently a required CI step and is intended for pre-release/manual validation.

### Application Skeleton Create-Project Validation

Run the local prerelease application skeleton create-project validation:

```bash
composer release:skeleton:validate
```

`release:skeleton:validate` exercises the real Composer `create-project` command for `evolvephp/skeleton` using repository-injected local package evidence. It creates validator-owned temporary paths, disables Packagist, sets `COMPOSER_DISABLE_NETWORK=1`, installs into a previously absent generated-project directory, validates the generated Composer manifest, confirms first-party packages are copied rather than symlinked to the source monorepo, runs `php bin/evolve doctor`, verifies `php bin/evolve route:list` emits `No routes are configured.`, generates `module:new Billing` and `plugin:new Cache` in the development install, verifies the generated application tests pass, verifies repeat generation refuses overwrites, verifies invalid/path-traversal names create nothing outside the application, verifies a `composer install --no-dev` production install still supports `doctor` and `route:list`, verifies Core missing-command and unknown-command usage errors, and confirms source repository state is preserved.

Public Packagist create-project availability is not yet claimed. Do not document `composer create-project evolvephp/skeleton ...` as generally available until publication is explicitly opened.

For an EvolvePHP 2 alpha consumer, the recommended root consumer settings are:

```json
{
    "minimum-stability": "alpha",
    "prefer-stable": true
}
```

These are root consumer settings. First-party package manifests must not add alpha stability policy. Existing first-party internal constraints remain `^2.0`; no package Composer manifest should add `@alpha`, `minimum-stability`, `prefer-stable` or a hard-coded `version` field. Top-level `@alpha` on only one package is insufficient for transitive alpha packages. Explicit root `@alpha` flags for every involved EvolvePHP package are a valid but more verbose alternative. Stable 2.0.0 consumers do not require alpha stability settings.

In prose: set `minimum-stability: alpha` and `prefer-stable: true` only in the root alpha consumer.

No package is published by `release:validate`, `release:split:validate`, `release:consumer:validate` or `release:skeleton:validate`. Remote package repositories, remote synchronization, Packagist registration, tags and releases remain deferred.

`release:validate` remains metadata/package-boundary validation. `release:split:validate` validates generated split history/root content. `release:consumer:validate` validates package-resolution semantics. `release:skeleton:validate` validates local prerelease create-project behavior. `supply-chain` remains network-dependent security/licence validation. `quality` remains ordinary root quality.

## Supply-Chain Security

Run the Composer lockfile security audit:

```bash
composer security:audit
```

`security:audit` checks the committed `composer.lock` dependency set through Composer audit, keeps `require-dev` included intentionally and fails on abandoned packages. Vulnerability, malware and dependency-policy findings must not be hidden by advisory suppression without an explicit future security decision. There is no advisory suppression list in this foundation.

Run the locked dependency licence-policy check:

```bash
composer licenses:check
```

`licenses:check` validates locked production and development packages from both `packages` and `packages-dev`. The approved locked dependency licence identifiers are:

- MIT
- BSD-3-Clause
- Apache-2.0

This allowlist reflects reviewed dependencies in the committed lockfile. `Apache-2.0` was deliberately reviewed because `jetbrains/phpstorm-stubs` currently appears in `packages-dev`. The allowlist is a repository engineering dependency-admission policy, not legal advice and not a legal opinion or a general legal compatibility statement. New or unknown licence identifiers fail closed and require deliberate review before acceptance. Approval here does not remove distribution or attribution obligations that may apply if future release artifacts redistribute third-party material.

Run the aggregate supply-chain gate:

```bash
composer supply-chain
```

`supply-chain` runs `security:audit` and then `licenses:check`. It is distinct from deterministic normal Composer quality tooling: `quality` remains the architecture, static-analysis, style and PHPUnit aggregate, while `supply-chain` may require network access for fresh remote advisory data.

CI executes the supply-chain gate through the required `Policy (PHP 8.4)` job after root dependencies are installed and before root policy tests. Dependabot version updates are repository-owned in `.github/dependabot.yml`: Composer updates watch `/`, GitHub Actions updates watch `/`, and internal EvolvePHP path packages are excluded from Composer version-update pull requests.

Dependabot alerts and Dependabot security updates are GitHub settings. `.github/dependabot.yml` alone does not prove those GitHub settings are enabled.

## VS Code Developer Experience

VS Code is optional developer tooling, not framework runtime configuration. The repository root is the VS Code workspace; no separate `.code-workspace` file is required for this phase.

The committed recommendations are intentionally minimal: EditorConfig for shared editor whitespace policy and Intelephense for PHP 8.4 language-analysis support. The Intelephense setting targets PHP 8.4 syntax and symbols for editor feedback, but it does not replace actual PHP 8.4+ execution.

The VS Code tasks execute `php` and `composer` from the integrated terminal environment. Before relying on them, confirm that:

```bash
php --version
composer --version
```

show PHP 8.4+ and Composer. If a contributor has multiple PHP versions, they should configure their local OS, terminal or VS Code user environment so the integrated terminal resolves PHP 8.4+. No machine-specific executable path is committed; do not commit one.

`composer.json` is the canonical source for Composer root scripts. VS Code tasks are convenience wrappers around those root scripts, and CI remains the remote enforcement layer.

The VS Code tasks map to the canonical commands as follows:

| Task | Canonical command | Category |
| --- | --- | --- |
| `EvolvePHP 2: Install Dependencies` | `composer install` | Dependency setup |
| `EvolvePHP 2: Quality` | `composer quality` | Non-mutating |
| `EvolvePHP 2: Tests` | `composer test` | Non-mutating |
| `EvolvePHP 2: Architecture` | `composer architecture` | Non-mutating |
| `EvolvePHP 2: Static Analysis` | `composer analyse` | Non-mutating |
| `EvolvePHP 2: Style Check` | `composer style:check` | Non-mutating |
| `EvolvePHP 2: Style Fix` | `composer style:fix` | Mutating |
| `EvolvePHP 2: Root Policy` | `php vendor/bin/phpunit --configuration phpunit.xml.dist tests/Architecture tests/Documentation` | Non-mutating |

Runtime debugging and Xdebug launch configuration are intentionally deferred because EvolvePHP 2 runtime implementation is not complete.

## PHPUnit Suites

PHPUnit 13 is owned by the EvolvePHP 2 root. It must not be added to any package manifest or production requirements.

The root PHPUnit configuration lives at:

```text
phpunit.xml.dist
```

It bootstraps through `vendor/autoload.php` and defines one named suite for each initial package:

| Suite | Test directory |
| --- | --- |
| `contracts` | `packages/contracts/tests` |
| `core` | `packages/core/tests` |
| `dev-tools` | `packages/dev-tools/tests` |
| `http` | `packages/http/tests` |
| `module` | `packages/module/tests` |
| `plugin` | `packages/plugin/tests` |
| `testing` | `packages/testing/tests` |

The former legacy root suite is preserved on `master`; the EvolvePHP 2 root suite is the canonical package-test harness on `2.x`.

## Static Analysis

PHPStan is the primary static analyzer for the EvolvePHP 2 root. PHPStan and PHP-CS-Fixer are root-only development tools. They must not be added to any package manifest or production requirements.

The distributable PHPStan configuration lives at:

```text
phpstan.neon.dist
```

The initial PHPStan level is `6`. PHPStan analyzes all seven package `src` and `tests` directories:

```text
packages/contracts/src
packages/contracts/tests
packages/core/src
packages/core/tests
packages/dev-tools/src
packages/dev-tools/tests
packages/http/src
packages/http/tests
packages/module/src
packages/module/tests
packages/plugin/src
packages/plugin/tests
packages/testing/src
packages/testing/tests
```

The root manually includes `phpstan/phpstan-phpunit` type-inference integration through `vendor/phpstan/phpstan-phpunit/extension.neon`.

No PHPStan baseline is allowed, and the configuration must not use `ignoreErrors`. Local PHPStan cache belongs in `.phpstan-cache/` and is ignored.

## Architecture Boundaries

Deptrac is root-owned architecture-boundary tooling. The maintained package identity is `deptrac/deptrac`; the abandoned `qossmic/deptrac` package identity is prohibited.

The configuration lives at:

```text
deptrac.php
```

Deptrac analyzes production source directories only:

```text
packages/contracts/src
packages/core/src
packages/dev-tools/src
packages/http/src
packages/module/src
packages/plugin/src
packages/testing/src
```

Package tests are excluded from Phase 2.5 Deptrac boundary analysis so test dependencies cannot weaken production rules. Physical package paths define layers, and package namespaces must match the package paths:

```text
Contracts -> packages/contracts/src/.* -> Evolve\Contracts\
Core      -> packages/core/src/.*      -> Evolve\Core\
DevTools  -> packages/dev-tools/src/.* -> Evolve\DevTools\
Http      -> packages/http/src/.*      -> Evolve\Http\
Module    -> packages/module/src/.*    -> Evolve\Module\
Plugin    -> packages/plugin/src/.*    -> Evolve\Plugin\
Testing   -> packages/testing/src/.*   -> Evolve\Testing\
```

The accepted dependency matrix is:

```text
Contracts -> none
Core      -> Contracts
DevTools  -> Contracts, Core, Module, Plugin
Http      -> Contracts, Core
Module    -> Contracts
Plugin    -> Contracts
Testing   -> Contracts, Core, Http, Module, Plugin
```

There is no production dependency on Testing. DevTools is development tooling and may depend on Contracts, Core, Module and Plugin. Testing may depend on all five production packages.

The root also models deliberate external standard layers:

```text
Contracts external standards
PsrContainer

Core external standards
PsrContainer

Http external standards
PsrHttpMessage
PsrHttpServer
```

`PsrContainer` represents the approved PSR-11 interoperability layer used by Core and, starting in Phase 5.4, by Contracts specifically for the public `ServiceDefinitionRegistrar` service-definition factory contract. Contracts remains first-party-inward and has no first-party EvolvePHP dependency; the PSR-11 reference documents the optional resolver argument accepted by component service-definition factories and does not make Contracts a container implementation. Core remains the implementation owner for the registry, frozen resolver, execution scopes and restricted registration coordinator. `PsrHttpMessage` represents the approved `Psr\Http\Message` namespace used by Http for PSR-7 message interfaces and PSR-17 factory interfaces, including `psr/http-message` and `psr/http-factory`. `PsrHttpServer` represents the approved PSR-15 server middleware/handler interface layer used by Http.

These PSR HTTP interfaces are external interoperability standards and do not change the first-party Evolve package dependency direction. Adding `psr/http-factory` does not require a new Deptrac external namespace layer because PSR-17 factory interfaces live under `Psr\Http\Message`. Http still depends inward on Contracts and Core, while the other first-party packages do not receive direct PSR HTTP access in this foundation.

Uncovered dependencies fail. No baseline or skipped violations are allowed. No graph is generated. New external dependency treatment requires deliberate architecture review.

## Coding Standards

PHP-CS-Fixer is the root coding-standard engine. The distributable configuration lives at:

```text
.php-cs-fixer.dist.php
```

The project style is based on PHP-FIG PER Coding Style 3.0 through PHP-CS-Fixer's `@PER-CS3x0` rule set. The floating `@PER-CS` alias is not used. The project explicitly enables alphabetical `ordered_imports` and `no_unused_imports`.

PHP-CS-Fixer checks the seven package `src` and `tests` directories plus the committed skeleton PHP config/bootstrap files. The extensionless skeleton executable is protected by syntax and create-project validation rather than distorting the Finder. The root architecture tests, root documentation tests, RFCs, `vendor/` and generated caches are excluded.

Risky rules are disabled. The `declare_strict_types` fixer is not enabled; strict-types policy for EvolvePHP 2 package PHP files is enforced by architecture tests.

## Lockfile Policy

`composer.lock` is committed for reproducible development and CI resolution.

The lockfile must be generated and updated through Composer under real PHP 8.4 execution. It must never be handwritten or generated with platform emulation.

## Continuous Integration

The canonical GitHub Actions workflow lives at:

```text
.github/workflows/quality.yml
```

The workflow name is `EvolvePHP 2 Quality`.

It runs for pull requests targeting `2.x`, pushes to `2.x` and manual dispatch. It uses least-privilege `contents: read` permissions and cancels superseded executions through workflow concurrency.

All jobs run on the explicit Ubuntu 24.04 runner, using the `ubuntu-24.04` label. The workflow has no initial dependency cache.

The policy job runs on PHP 8.4. The `Policy (PHP 8.4)` job validates the root Composer manifest and lockfile before installation, installs root dependencies from the committed lockfile with `composer install`, runs package split validation, runs skeleton create-project validation, runs supply-chain checks and runs the root Architecture and Documentation policy tests through root PHPUnit 13:

```bash
php vendor/bin/phpunit --configuration phpunit.xml.dist tests/Architecture tests/Documentation
```

Those root policy tests validate EvolvePHP 2 repository governance and documentation. The preserved EvolvePHP 1 runtime is not part of the EvolvePHP 2 compatibility claim.

The required `Workspace quality (PHP 8.4)` and `Workspace quality (PHP 8.5)` context names are preserved for governance compatibility. The word `Workspace` in those protected job names no longer means a tracked `workspace/` directory exists.

The root quality matrix runs PHP 8.4 and PHP 8.5. Each matrix entry validates Composer metadata before installation, installs from `composer.lock`, and runs:

```bash
composer quality
```

The initial Phase 2.6 CI matrix has successfully executed. Root quality passes on PHP 8.4 and PHP 8.5, and the root policy job passes on PHP 8.4 for the current tooling and package foundation. This evidence applies to the current workspace, tooling and package foundation only.

PHP 8.5 evidence for the current root quality pipeline is recorded by the Phase 2.6 CI matrix.

The EvolvePHP 2 runtime implementation is incomplete, so this is not a broader runtime-production compatibility claim.

The workflow must not run `composer update`, use a platform-requirement bypass or replace the approved aggregate quality command with duplicated individual quality commands. There is no platform-requirement bypass in CI.

Action dependencies are pinned by immutable full-SHA references. The reviewed release comments must remain beside those SHAs so future audits can connect each commit pin to its intended release tag.

## Compatibility Evidence

EvolvePHP 2 requires PHP 8.4 as the baseline. The Phase 2.6 CI matrix has successfully executed in GitHub Actions: the current root quality pipeline passes on PHP 8.4 and PHP 8.5, and the root policy job passes on PHP 8.4.

This verifies the current workspace, tooling and package foundation only. The preserved EvolvePHP 1 runtime is excluded, and the EvolvePHP 2 runtime implementation remains incomplete.

## Deferred Work

The following work remains deferred:

- committed-ref package split validation after a reviewed Phase 4.7 commit
- package publication, tags and GitHub releases
- Runtime framework implementation beyond the completed Phase 4 HTTP package foundation
- Phase 5 module/plugin runtime work
- broader developer tooling beyond `module:new` and `plugin:new`
- Phase 6.4F broader Testing utilities beyond the command-output recorder
