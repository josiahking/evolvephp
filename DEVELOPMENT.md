# EvolvePHP 2 Development

The repository root is the canonical EvolvePHP 2 Composer development root for the modular monorepo.

The root resolves local packages, owns development tooling and runs EvolvePHP 2 package quality checks. It is not a release package, a publishable framework package, an application skeleton, runtime framework code, a Composer plugin or a production deployment artifact.

The preserved EvolvePHP 1 runtime and the former legacy root suite remain preserved on `master` and in Git history. The EvolvePHP 2 root suite now runs from this repository root.

## Requirements

- PHP 8.4+
- Composer 2
- Git

EvolvePHP 2 requires PHP 8.4 as its baseline. GitHub Actions exercises the current root quality pipeline on PHP 8.4 and PHP 8.5 for the current tooling and package foundation.

Platform emulation must not be used for runtime compatibility claims. Do not use `config.platform.php`, `--ignore-platform-req=php` or `--ignore-platform-reqs` to generate the root lockfile or claim PHP compatibility.

GitHub CLI is optional. It is useful only for maintainers and contributors who choose GitHub CLI workflows; it is not a framework runtime requirement.

## Portable Workstation Bootstrap

Start from the expected branch or ref and fetch the latest remote state before new work:

```bash
git fetch --prune
git status
```

The working tree should be clean before a new change begins. The committed lockfile is authoritative for normal setup, so ordinary setup uses `composer install`, not `composer update`.

Validate the Composer manifest and platform before installing dependencies:

```bash
composer validate --strict --check-lock
composer check-platform-reqs --lock
composer install --no-interaction
```

Do not bypass platform requirements to claim compatibility. Unexpected Composer plugin prompts should be reviewed before they are approved.

EvolvePHP owns text checkout line endings through `.gitattributes`. Repository text is checked out using LF, local or system `core.autocrlf` should not determine EvolvePHP text checkout behavior, and `.editorconfig` and PHP-CS-Fixer are aligned with the repository-owned LF policy.

Inspect the effective EOL state portably when needed:

```bash
git ls-files --eol
```

Before development begins, a ready checkout has the expected branch or ref, a clean working tree, valid PHP and Composer platform requirements, dependencies installed from `composer.lock`, repository files showing the expected LF behavior, and passing root quality:

```bash
composer quality
```

## Package Resolution

Packages are resolved from one Composer path repository:

```text
packages/*
```

The root maps each initial package explicitly to `2.0.x-dev` inside the path repository:

- `evolvephp/contracts`
- `evolvephp/database-contracts`
- `evolvephp/database-pdo`
- `evolvephp/cache-memory`
- `evolvephp/session-contracts`
- `evolvephp/lock-contracts`
- `evolvephp/queue-contracts`
- `evolvephp/queue-memory`
- `evolvephp/storage-contracts`
- `evolvephp/storage-local`
- `evolvephp/secret-contracts`
- `evolvephp/bridge-contracts`
- `evolvephp/bridge-psr`
- `evolvephp/bridge-laravel`
- `evolvephp/bridge-symfony`
- `evolvephp/bridge-remote`
- `evolvephp/core`
- `evolvephp/job`
- `evolvephp/scheduler`
- `evolvephp/migration`
- `evolvephp/view`
- `evolvephp/view-twig`
- `evolvephp/view-blade`
- `evolvephp/i18n`
- `evolvephp/insight`
- `evolvephp/dev-tools`
- `evolvephp/http`
- `evolvephp/http-client`
- `evolvephp/observe`
- `evolvephp/mcp`
- `evolvephp/ai`
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
composer test:database-contracts
composer test:database-pdo
composer test:cache-memory
composer test:session-contracts
composer test:lock-contracts
composer test:queue-contracts
composer test:queue-memory
composer test:storage-contracts
composer test:storage-local
composer test:secret-contracts
composer test:bridge-contracts
composer test:bridge-psr
composer test:bridge-laravel
composer test:bridge-symfony
composer test:bridge-remote
composer test:core
composer test:job
composer test:scheduler
composer test:migration
composer test:view
composer test:view-twig
composer test:view-blade
composer test:i18n
composer test:insight
composer test:observe
composer test:mcp
composer test:ai
composer test:dev-tools
composer test:http
composer test:http-client
composer test:module
composer test:plugin
composer test:testing
```

Insight diagnostic observation watchers are composed explicitly through `DiagnosticPipeline`. Installing Insight does not discover watchers, mutate Core instrumentation, read environment variables or create global watcher registration. Watcher candidates must keep the triggering Core execution identifier and enter the existing collector capture path so filtering, sensitive-name redaction, deterministic sampling and bounded dropped-entry accounting happen before detached persistence.

Core execution observations can be delivered to no consumer, one consumer or an explicitly ordered list of generic observation consumers. Consumer failures remain isolated from application work, continue delivery to later consumers and are reported independently as safe instrumentation failures on the execution outcome. Core does not discover consumers, inspect containers or install global observation registries.

Applications may explicitly configure ordered execution-context attachers for caller-owned external lifecycle state. Core attaches after creating the immutable execution context and detaches successful attachments before execution-scope close, without storing external SDK objects or mutable ambient state in `ExecutionContext`. Attachment-establishment failure is a non-fatal instrumentation failure. Attachment-detach failure is an execution-isolation cleanup failure, is aggregated with any later scope-close failure and requires quarantine. Core still does not provide tracing, metrics, logs, propagation, exporters or an OpenTelemetry implementation.

`ExecutionContext` may carry immutable `ExecutionContextValues` for an already-resolved locale and timezone belonging to that one execution. Either value may be absent and remains `null`; Core does not infer PHP's current locale or default timezone, create an ambient current execution, call process-global locale/timezone mutation APIs or implement translation, formatting, locale negotiation or fallback policy. Application localization code can consume the explicit context values when it owns those higher-level policies.

Insight diagnostic reads use `DiagnosticBatchReader` and `DiagnosticQueryService`, not additional methods on `DiagnosticBatchStore`. `DiagnosticBatchStore` remains the save/find/latest persistence boundary; `DiagnosticBatchReader` owns exact detail lookup and bounded cursor queries over detached snapshots. Applications must supply a `DiagnosticAccessPolicy`; Insight provides `DashboardRoutes::native` for explicit native PHP list/detail route composition with caller-owned PSR responses, `ViewRenderer`, i18n catalogs and localization context. It does not install a default policy or automatically register routes, create storage, authenticate users or define roles. Route composition requires an explicit `DashboardExposure::localDevelopment()` or `DashboardExposure::productionAuthorized()` choice, and every read still uses `DiagnosticAccessPolicy`. Query support is limited to bounded cursor pagination and exact persisted execution-kind/category/name filters over detached snapshots.

First-party stores use count-bounded retention. The in-memory store is suitable for tests and short-lived local development, while the SQLite store uses caller-supplied `PDO` local-development persistence. Local Insight diagnostics are not production observability export, OpenTelemetry or Evolve Observe. Sensitive operational machine names such as token, access-token, API-key, session and set-cookie fields are redacted before accepted diagnostic values are persisted.

Run the isolated legacy Remote Bridge compatibility client tests:

```bash
php compat/legacy-http-client/tests/run.php
```

Validate the isolated legacy Remote Bridge compatibility client manifest:

```bash
composer validate --working-dir=compat/legacy-http-client --strict
```

Run static analysis for the PHP 7.4 compatibility source using the root-installed PHPStan binary:

```bash
php vendor/bin/phpstan analyse --configuration compat/legacy-http-client/phpstan.neon.dist --memory-limit=1G --no-progress
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

## Database Guidance

DevTools includes an experimental database capability catalogue and advisor for
developer planning. The public `Evolve\DevTools\Database` models accept explicit
workload profiles and explicit database-family definitions, then produce
deterministic structured advice. The built-in catalogue covers family-level
guidance for analytical warehouse, distributed SQL, document, graph, in-memory,
key-value, ledger/immutable, multi-model, relational, search/index, time-series,
vector and wide-column storage families.

Database capabilities are conservative family-level guidance: representative
systems in a family are commonly suited to the characteristic, but individual
products are not certified by the catalogue. Example engines are documentation
evidence only and do not add dependencies.

The advisor performs no runtime inspection, infrastructure inspection, driver
detection, SQL analysis, telemetry, network research, AI recommendation,
database installation, vendor ranking or compatibility certification. Multiple
workload profiles are evaluated independently through `adviseMany()` for
polyglot-persistence planning; they are not merged into a composite score.

## Beta Publisher Preparation

`release:publisher:prepare` is a separate publisher-preparation operator. It defaults to dry-run, no-network and no-write behavior, including no temporary output files. Its only output is JSON on stdout or a bounded failure message on stderr. The existing `release:plan`, split validator and Alpha/Stable/Beta consumer validators retain their roles. There are still 34 proposed independent repositories and no public Beta has been issued.

Use an absolute PHP 8.4 executable appropriate to the host. Supply an independently reviewed full source commit, tree and branch with `--source-commit`, `--source-tree` and `--source-branch`; optionally select a checkout with `--root`. The equivalent Composer entry point is `composer release:publisher:prepare` with those same options. Missing pins fail closed; reading HEAD yourself does not constitute release approval. A clean committed checkout is required, so an uncommitted implementation candidate is deliberately rejected. Store redirected evidence outside the checkout.

The operator checks the canonical inventory, proposed destination order, runtime dependency order, package manifests, license contents and safe Git objects. It refuses dirty tracked/index/untracked state, hidden index entries, replacement refs, shallow history, local filters/includes and separate worktree configuration. Ignored files are not release inputs. Use a trusted Git executable and local configuration; source access must remain exclusive during inspection. Git inspection disables optional locks, replacement objects, lazy fetching, global/system configuration, credential prompts and protocols. Child processes receive only runtime path variables and fixed Git controls. No tokens are accepted, read or printed; CI and available credentials never grant publication authorization. Unsupported or duplicate options fail without echoing their values. `--publish` always fails, including when credentials are available.

Every plan binds the source, package tree, Composer/license blobs, destination, dependency order and proposed `refs/tags/2.0.0-beta.1` in a deterministic `plan_sha256`. This digest hashes the compact JSON with unescaped slashes, excluding the digest field. A package tree is not a split commit: `split_commit` and `verified_tag_object` remain null. Required attestations describe independently repeated split-history validation, exact split-root tree equality and a public tag peeled to that verified split commit. Preparation neither computes splits nor verifies a public tag.

Contracts is the pilot. All 33 remaining packages retain a separate gate requiring accepted pilot evidence and later explicit approval; runtime dependencies precede dependants. All plans report publication readiness as blocked. Destination ownership, split/tag attestations, explicit publication authorization and a separately reviewed executor are outstanding. Credentials, remote repositories, tags and Packagist registration belong to that later authorization boundary. There is no executable publication path in this tool.

### Offline pilot executor preflight

`tools/release-package-executor.php` is a separate, bounded offline preflight for `evolvephp/contracts`. Pass a saved publisher plan with `--plan=<external-json-file>` and optionally `--root=<source-checkout>`; run it with PHP 8.4. The plan must retain its exact `plan_sha256` and full source commit/tree/branch pins. The executor checks the local source object and `packages/contracts` tree against the plan, the proposed `josiahking/evolvephp-contracts` destination, and the exact `refs/tags/2.0.0-beta.1` identity. It accepts only the pilot. Put the plan outside the checkout so the publisher can produce it from a clean tree.

The default result is deterministic JSON with `publication_enabled: false`, ownership `unverified`, remote state `unverified`, and explicit blockers. It performs read-only local Git inspection, needs no credential, and makes no network request or public artifact. `--publish`, tokens and other unsupported options fail with bounded diagnostics. A supplied local evidence object can be inspected by library callers, but reported remote commit, tag object and peeled tag commit remain unverified and cannot authorize a write. Partial or conflicting split/remote/tag identities fail closed. This preflight does not independently compute deterministic split history or verify a remote repository or tag.

Future execution requires a separately reviewed authorization artifact naming the immutable source, plan digest, pilot package tree, verified destination owner, deterministic split commit, remote commit, exact tag ref, tag object and peeled commit. It also requires independent remote absence/ownership proof, a write mechanism with safe partial-publication recovery, and explicit maintainer approval. Credentials, flags, environment variables and CI state are never authorization. No publication path is implemented here.
### Offline observations and recovery

Optional `--observations=<external-json-file>` accepts an untrusted offline snapshot for comparison only. It never contacts a destination, verifies ownership or promotes any package to published. The exact version-1 schema uses these keys in order: `version`, `source` (the plan's branch/commit/tree object), `packages` (all 34 entries in canonical order). Each entry has `name`, `destination`, `split_commit`, `split_tree`, `remote_commit`, `tag_ref`, `tag_object`, `tag_commit`. The tag ref must be `refs/tags/2.0.0-beta.1`. All five object IDs must be null for a reported absence, or nonzero full SHA-1 strings for a complete report. Complete reports must bind the split tree to the package tree and both remote/tag commits to the reported split commit. Annotated tag objects may differ from their peeled commits; lightweight tag objects equal the commit. These are reported identities, never verified attestations.

Malformed, reordered, duplicate, mismatched or partial reports fail closed. Even a consistent all-package report remains unverified and blocked. Mixed absent/complete packages are partial state, including a reported pilot-only publication: stop, preserve the external evidence, independently inspect every destination and tag, and obtain a separately reviewed recovery decision. Do not delete/recreate tags, force-push, overwrite metadata or automatically retry writes. The operator has no checkpoint, remote mutation or resume mechanism. Repeating identical source/options/observation bytes produces identical output; the observations byte digest makes snapshot changes inspectable. Correcting an invalid report permits a fresh dry-run but never authorizes publication.

Local validation uses isolated fixture repositories and external evidence. The read-only CI preparation check additionally requires a successful blocked plan and rejection of `--publish`; `contents: read` and checkout credential isolation are retained. The Next manual's existing unpublished status remains accurate; these maintainer-only preparation instructions introduce no consumer API or installation change.

## Local Beta Distribution Planning

The approved distribution model keeps this monorepo authoritative and proposes 34 independently tagged public GitHub split repositories, with each package individually registered on Packagist after separate authorization. The intended sequence is monorepo source, deterministic package splits, independent repositories, independently verified package tags, Packagist registration, then consumer installation. None of those public publication steps is performed by local readiness tooling.

The version-1 inventory in `release-packages.json` remains canonical. `tools/release-package-destinations.json` separately maps all 34 packages, in that same order, to proposed `evolvephp-<slug>` repositories under the owner recorded in that mapping. Its `proposed-unverified` status means that repository existence and ownership have not been checked. `2.0.0-beta.1` is a proposed simulation version, not a released Beta.

From a clean committed source checkout, generate the offline JSON plan:

```bash
composer release:plan
```

For an independently reviewed source identity, supply the full accepted commit and tree IDs with `--source-commit=<40-character-sha>` and `--source-tree=<40-character-sha>`, and the accepted branch with `--source-branch=<branch>`, after Composer's `--` separator. `--root=<checkout>` selects another local source checkout. The defaults bind to the current HEAD commit, tree and branch. Detached CI checkouts use the branch label `HEAD`; this is not proof of protected-branch membership. Planning accepts `2.x`, task branches under `feature/`, or detached HEAD; it rejects `master`, dirty state, hidden index entries, replacement refs, mismatched identities and unsupported arguments.

Review the source commit/tree against the accepted immutable source, the exact 34 names and ordering, every proposed destination, and each `package_tree` and `composer_blob`. Compare two outputs byte for byte for the same source. Save evidence outside the checkout: an untracked output file inside it makes subsequent planning fail. The planner uses read-only Git object inspection and preserves the index, worktree and refs. It neither computes split-history commits nor verifies tags: `composer release:split:validate` remains the separate disposable-clone split/history check. A later publication review must prove each split tree equals the planned package tree and independently verify every package tag against the approved source.

An uncommitted implementation checkout is deliberately rejected. During implementation, PHPUnit uses disposable committed fixtures to exercise successful plans, and the live checkout exercises dirty-source rejection. Do not stage or commit solely to bypass this guard.

`composer release:consumer:validate` retains the Alpha/Stable matrix and adds these synthetic Beta cases using the actual Composer resolver:

| Case | Root requirements and stability | Expected result |
| --- | --- | --- |
| I | HTTP `^2.0`, default stable | Stability rejection |
| J | HTTP `2.0.0-beta.1`, default stable | Transitive stability rejection |
| K | HTTP `^2.0@beta` only, default stable | Transitive stability rejection |
| L | HTTP `^2.0`, minimum-stability: beta and prefer-stable: true | HTTP, Core and Contracts select Beta |
| M | Explicit root `^2.0@beta` for HTTP, Core and Contracts | All three select Beta |
| N | All 34 root requirements `^2.0`, minimum-stability: beta and prefer-stable: true | Complete Beta graph |
| O | All 34 root requirements exactly `2.0.0-beta.1` | Complete Beta graph |

Root stability flags do not automatically propagate to dependencies. Package-local first-party constraints remain `^2.0`; PHP remains `^8.4` and licensing remains BSD-3-Clause. No first-party package gains a version field or a root stability policy. The validator sets `COMPOSER_DISABLE_NETWORK=1`, disables Packagist in synthetic consumers, and creates synthetic version tags only in disposable local repositories. Lock-only Beta resolution is not a public installation or production-readiness claim. The existing independently installed MCP consumer case remains separate.

The planner has no publishing mode, needs no credentials or network, and cannot create repositories, push commits, create local or remote release tags, change remotes, register Packagist packages, or publish releases. Future repository creation, synchronization, tags, registration and releases require explicit publication authorization and independently reviewed evidence. Packages are not yet independently published; public Composer installation has not been verified.

## Release Validation

Run deterministic/offline package release-readiness validation:

```bash
composer release:validate
```

The release packages are mapped explicitly in `release-packages.json`. The dependency-compatible map contains thirty-four packages in this order: contracts, database-contracts, database-pdo, cache-memory, session-contracts, lock-contracts, queue-contracts, queue-memory, storage-contracts, storage-local, secret-contracts, bridge-contracts, core, job, scheduler, migration, view, view-twig, view-blade, i18n, module, plugin, http, insight, http-client, observe, mcp, ai, bridge-psr, bridge-laravel, bridge-symfony, bridge-remote, testing and dev-tools. Package-local README and licence files exist so future split roots carry consumer documentation and legal text naturally. Package-local licences must remain identical to root `LICENSE.md`.

No package is being published by this command. No remote repositories are contacted, no tags/releases are created, and no split repositories are synchronized. Package Composer manifests remain authoritative for package metadata.

`release:validate` is distinct from `quality`. It is also distinct from network-dependent `supply-chain`. Package splitting is validated by `release:split:validate`, and prerelease consumer stability is validated by the offline consumer matrix. RFC 0003 remains authoritative for release and version policy.

### Package Split Validation

Run full deterministic package split validation:

```bash
composer release:split:validate
```

`release:split:validate` reads `release-packages.json`, creates a disposable local clone with `--no-hardlinks`, runs every mapped `git subtree` split twice, validates repeated split SHA equality, validates exact subtree/root tree equality, validates exact inventory equality, validates generated split-root Composer manifests, and confirms package-specific Git history is retained. It creates no remote repository, pushes nothing, creates no source tags, works only on committed Git history/ref and runs in Policy PHP 8.4 CI. With no extra options, this command always validates the complete release-package map and remains the required mode for CI and release preparation.

For ordinary local committed-ref checks, the validator also accepts an explicit comparison base:

```bash
php tools/validate-package-splits.php --ref=<implementation-sha> --changed-from=<base-sha> --composer=<composer.phar>
```

Targeted mode compares committed Git paths between `--changed-from` and `--ref`, selects affected release packages from `release-packages.json` in canonical map order, and sends every selected package through the same deterministic split validation path used by full mode. Package directory matching is boundary-aware: a change under `packages/core/...` selects `evolvephp/core`, while an unrelated path such as `packages/core-extra/...` does not.

The targeted validator fails safe to full validation when the comparison includes paths that can affect release package interpretation or split semantics, including `release-packages.json`, split/release validation tooling, root Composer command wiring, GitHub release-validation workflow wiring, first-party package `composer.json` manifests, package/dependency-boundary configuration and shared release/package-publication infrastructure. Unclassified infrastructure paths also force full validation unless they are exact mapped package paths or explicitly safe documentation paths. Ordinary repository documentation-only changes do not force full mode by themselves.

A targeted comparison can select zero release packages. In that case the validator reports that no affected release packages require local split validation, still checks source repository state preservation, and exits successfully.

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

VS Code is optional developer tooling, not framework runtime configuration. The repository root is the VS Code workspace; no separate `.code-workspace` file is required.

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
| `database-contracts` | `packages/database-contracts/tests` |
| `database-pdo` | `packages/database-pdo/tests` |
| `cache-memory` | `packages/cache-memory/tests` |
| `session-contracts` | `packages/session-contracts/tests` |
| `lock-contracts` | `packages/lock-contracts/tests` |
| `queue-contracts` | `packages/queue-contracts/tests` |
| `queue-memory` | `packages/queue-memory/tests` |
| `storage-contracts` | `packages/storage-contracts/tests` |
| `storage-local` | `packages/storage-local/tests` |
| `secret-contracts` | `packages/secret-contracts/tests` |
| `bridge-contracts` | `packages/bridge-contracts/tests` |
| `bridge-psr` | `packages/bridge-psr/tests` |
| `bridge-laravel` | `packages/bridge-laravel/tests` |
| `bridge-symfony` | `packages/bridge-symfony/tests` |
| `bridge-remote` | `packages/bridge-remote/tests` |
| `core` | `packages/core/tests` |
| `job` | `packages/job/tests` |
| `scheduler` | `packages/scheduler/tests` |
| `migration` | `packages/migration/tests` |
| `view` | `packages/view/tests` |
| `view-twig` | `packages/view-twig/tests` |
| `view-blade` | `packages/view-blade/tests` |
| `i18n` | `packages/i18n/tests` |
| `insight` | `packages/insight/tests` |
| `observe` | `packages/observe/tests` |
| `mcp` | `packages/mcp/tests` |
| `ai` | `packages/ai/tests` |
| `dev-tools` | `packages/dev-tools/tests` |
| `http` | `packages/http/tests` |
| `http-client` | `packages/http-client/tests` |
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

The initial PHPStan level is `6`. PHPStan analyzes all twenty-seven package `src` and `tests` directories:

```text
packages/contracts/src
packages/contracts/tests
packages/database-contracts/src
packages/database-contracts/tests
packages/database-pdo/src
packages/database-pdo/tests
packages/cache-memory/src
packages/cache-memory/tests
packages/session-contracts/src
packages/session-contracts/tests
packages/lock-contracts/src
packages/lock-contracts/tests
packages/queue-contracts/src
packages/queue-contracts/tests
packages/queue-memory/src
packages/queue-memory/tests
packages/storage-contracts/src
packages/storage-contracts/tests
packages/storage-local/src
packages/storage-local/tests
packages/secret-contracts/src
packages/secret-contracts/tests
packages/bridge-contracts/src
packages/bridge-contracts/tests
packages/bridge-psr/src
packages/bridge-psr/tests
packages/bridge-laravel/src
packages/bridge-laravel/tests
packages/bridge-symfony/src
packages/bridge-symfony/tests
packages/bridge-remote/src
packages/bridge-remote/tests
packages/core/src
packages/core/tests
packages/job/src
packages/job/tests
packages/scheduler/src
packages/scheduler/tests
packages/insight/src
packages/insight/tests
packages/observe/src
packages/observe/tests
packages/mcp/src
packages/mcp/tests
packages/ai/src
packages/ai/tests
packages/dev-tools/src
packages/dev-tools/tests
packages/http/src
packages/http/tests
packages/http-client/src
packages/http-client/tests
packages/module/src
packages/module/tests
packages/plugin/src
packages/plugin/tests
packages/testing/src
packages/testing/tests
```

The root manually includes `phpstan/phpstan-phpunit` type-inference integration through `vendor/phpstan/phpstan-phpunit/extension.neon`.

No PHPStan baseline is allowed, and the configuration must not use `ignoreErrors`. Local PHPStan cache belongs in `.phpstan-cache/` and is ignored.

Observe, MCP and AI source/test tooling coverage is included in PHPStan and PHP-CS-Fixer through their respective package source and test paths.

## Architecture Boundaries

Deptrac is root-owned architecture-boundary tooling. The maintained package identity is `deptrac/deptrac`; the abandoned `qossmic/deptrac` package identity is prohibited.

The configuration lives at:

```text
deptrac.php
```

Deptrac analyzes production source directories only:

```text
packages/contracts/src
packages/database-contracts/src
packages/database-pdo/src
packages/cache-memory/src
packages/session-contracts/src
packages/lock-contracts/src
packages/queue-contracts/src
packages/queue-memory/src
packages/storage-contracts/src
packages/storage-local/src
packages/secret-contracts/src
packages/bridge-contracts/src
packages/bridge-psr/src
packages/bridge-laravel/src
packages/bridge-symfony/src
packages/bridge-remote/src
packages/core/src
packages/job/src
packages/scheduler/src
packages/insight/src
packages/observe/src
packages/mcp/src
packages/ai/src
packages/dev-tools/src
packages/http/src
packages/http-client/src
packages/module/src
packages/plugin/src
packages/testing/src
```

Package tests are excluded from Deptrac boundary analysis so test dependencies cannot weaken production rules. Physical package paths define layers, and package namespaces must match the package paths:

```text
Contracts -> packages/contracts/src/.* -> Evolve\Contracts\
DatabaseContracts -> packages/database-contracts/src/.* -> Evolve\Database\Contracts\
DatabasePdo -> packages/database-pdo/src/.* -> Evolve\Database\Pdo\
CacheMemory -> packages/cache-memory/src/.* -> Evolve\Cache\Memory\
SessionContracts -> packages/session-contracts/src/.* -> Evolve\Session\Contracts\
LockContracts -> packages/lock-contracts/src/.* -> Evolve\Lock\Contracts\
QueueContracts -> packages/queue-contracts/src/.* -> Evolve\Queue\Contracts\
QueueMemory -> packages/queue-memory/src/.* -> Evolve\Queue\Memory\
StorageContracts -> packages/storage-contracts/src/.* -> Evolve\Storage\Contracts\
StorageLocal -> packages/storage-local/src/.* -> Evolve\Storage\Local\
SecretContracts -> packages/secret-contracts/src/.* -> Evolve\Secret\Contracts\
BridgeContracts -> packages/bridge-contracts/src/.* -> Evolve\Bridge\Contracts\
BridgePsr -> packages/bridge-psr/src/.* -> Evolve\Bridge\Psr\
BridgeLaravel -> packages/bridge-laravel/src/.* -> Evolve\Bridge\Laravel\
BridgeSymfony -> packages/bridge-symfony/src/.* -> Evolve\Bridge\Symfony\
BridgeRemote -> packages/bridge-remote/src/.* -> Evolve\Bridge\Remote\
Core      -> packages/core/src/.*      -> Evolve\Core\
Job       -> packages/job/src/.*       -> Evolve\Job\
Scheduler -> packages/scheduler/src/.* -> Evolve\Scheduler\
Insight   -> packages/insight/src/.*   -> Evolve\Insight\
Observe   -> packages/observe/src/.*   -> Evolve\Observe\
Mcp       -> packages/mcp/src/.*       -> Evolve\Mcp\
Ai        -> packages/ai/src/.*        -> Evolve\Ai\
DevTools  -> packages/dev-tools/src/.* -> Evolve\DevTools\
Http      -> packages/http/src/.*      -> Evolve\Http\
HttpClient -> packages/http-client/src/.* -> Evolve\Http\Client\
Module    -> packages/module/src/.*    -> Evolve\Module\
Plugin    -> packages/plugin/src/.*    -> Evolve\Plugin\
Testing   -> packages/testing/src/.*   -> Evolve\Testing\
```

The accepted dependency matrix is:

```text
Contracts -> none
DatabaseContracts -> Contracts
DatabasePdo -> Contracts, DatabaseContracts
CacheMemory -> PsrSimpleCache, PsrClock
SessionContracts -> Contracts
LockContracts -> Contracts
QueueContracts -> Contracts
QueueMemory -> QueueContracts
StorageContracts -> Contracts
StorageLocal -> StorageContracts
SecretContracts -> Contracts
BridgeContracts -> Contracts
BridgePsr -> BridgeContracts, Core, Http
BridgeLaravel -> BridgeContracts, BridgePsr, PsrHttpMessage, LaravelHost
BridgeSymfony -> BridgeContracts, BridgePsr, PsrHttpMessage, SymfonyHost
BridgeRemote -> BridgeContracts, BridgePsr, PsrHttpMessage, PsrHttpClient, PsrHttpServer
Core      -> Contracts
Job       -> Core, QueueContracts
Insight   -> Core, DatabaseContracts, Http, View, I18n, QueueContracts, StorageContracts, PsrSimpleCache, PsrHttpClient, PsrHttpMessage, PsrHttpServer
Observe   -> Core, DatabaseContracts, Http, HttpClient, Job, QueueContracts, StorageContracts, OpenTelemetryApi, OpenTelemetrySdk, OpenTelemetrySemConv, PsrHttpClient, PsrHttpMessage, PsrHttpServer, PsrSimpleCache
Mcp       -> Contracts, Core, SecretContracts, McpSdk, PsrHttpClient, PsrHttpMessage, PsrHttpServer
Ai        -> (no outward first-party dependencies)
DevTools  -> Contracts, Core, Module, Plugin
Http      -> Contracts, Core
HttpClient -> PsrHttpMessage, PsrHttpClient
Module    -> Contracts
Plugin    -> Contracts
Testing   -> Contracts, Core, Http, Module, Plugin
```

There is no production dependency on Testing. DatabaseContracts is an optional outward package and may depend only on Contracts. DatabasePdo is an optional outward adapter package and may depend only on Contracts and DatabaseContracts. CacheMemory is an optional outward package and may depend only on PSR Simple Cache and PSR Clock. It uses PSR-16 as the cache contract, has application-lifetime object state rather than execution reset state, and does not provide PSR-6, Redis, APCu, Memcached, database, HTTP, Insight, Observe, telemetry, session or lock behavior. SessionContracts is an optional outward package and may depend only on Contracts. It defines execution-owned mutable session data, adapter close/reset cleanup through ResetParticipant and sensitive identifier redaction, but it does not provide native PHP session integration, storage backends, cookies, CSRF, flash, authentication, distributed locks, Insight or Observe behavior. BridgeContracts is an optional outward package and may depend only on Contracts. BridgePsr is an optional outward package and may depend on BridgeContracts, Core, Http and PSR HTTP message interfaces. BridgeLaravel is an optional outward package and may depend on BridgeContracts, BridgePsr, PSR HTTP message interfaces and the narrow LaravelHost layer. BridgeSymfony is an optional outward package and may depend on BridgeContracts, BridgePsr, PSR HTTP message interfaces and the narrow SymfonyHost layer. BridgeRemote is an optional outward package and may depend on BridgeContracts, BridgePsr, PSR HTTP message and factory interfaces, PSR-18 HTTP client interfaces and PSR HTTP server-handler interfaces. Insight is an optional outward package and may depend on Core, DatabaseContracts, Http, QueueContracts and StorageContracts, plus its declared PSR Simple Cache, HTTP client, HTTP message and HTTP server interfaces. Its direct `evolvephp/http:^2.0` dependency supports explicit incoming server diagnostics through PSR-15; Core and HTTP remain independent of Insight. Observe is an optional outward package and may depend on Core, DatabaseContracts, Http, HttpClient, Job, QueueContracts, StorageContracts, OpenTelemetryApi, OpenTelemetrySdk, OpenTelemetrySemConv, PSR HTTP client and message interfaces, PSR HTTP server interfaces and PSR Simple Cache. The Core edge is for generic execution lifecycle contracts, and the Http edge is for route-template enrichment through public routing state while Core and HTTP remain OpenTelemetry-neutral. The SDK edge is optional package functionality for suggested SDK resource and sampler typing, not a mandatory published Observe runtime dependency. OpenTelemetrySemConv is a mandatory runtime edge because Observe production source uses stable semantic-convention constants. DevTools is development tooling and may depend on Contracts, Core, Module and Plugin. Testing may depend on Contracts, Core, Http, Module and Plugin; it does not depend on DatabaseContracts, DatabasePdo, CacheMemory, SessionContracts, BridgeContracts, BridgePsr, BridgeLaravel, BridgeSymfony or BridgeRemote.

LockContracts is an optional outward package and may depend only on Contracts. It defines a non-blocking acquisition attempt and explicit lease ownership capability, with safe cleanup available through explicit execution reset registration. It contains no adapter and promises no fairness, fencing, linearizability, consensus, exact wall-clock expiry, or particular distributed locking algorithm.

QueueMemory is an optional outward package and may depend only on QueueContracts. It provides an object-local, deterministic, non-durable FIFO adapter for tests and local development. Receive is non-blocking; acknowledgement is terminal success and rejection is terminal discard for this adapter. Abandoned deliveries are not restored, and instances share no state.

The database capability advisor in DevTools is developer guidance only. It evaluates explicit workload profiles against explicit static catalogs deterministically: required capabilities are hard gates, excluded capabilities are hard conflicts, preferred score is only the count of matched preferred capabilities, ties remain explicit, and advice includes per-family explainability. The built-in catalog contains exactly thirteen database families. Built-in engines are examples only, not dependencies; family capabilities describe representative family characteristics; custom catalogs are supported; `adviseMany()` evaluates profiles independently for polyglot planning. This slice is not product/version compatibility certification and has no application/runtime inspection, installed-driver inference, SQL inspection, telemetry, network/vendor lookup, hidden weights, popularity/pricing/cloud-provider ranking, automatic installation, automatic configuration, automatic migration, automatic database switching, universal CRUD/query/database API, `database:recommend` command or other CLI adapter.

The root also models deliberate external standard layers:

```text
Contracts external standards
PsrContainer

CacheMemory external standards
PsrSimpleCache
PsrClock

Core external standards
PsrContainer

Insight external standards
PsrSimpleCache
PsrHttpClient
PsrHttpMessage
PsrHttpServer

MCP external standard

McpSdk

Observe external standards
OpenTelemetryApi
OpenTelemetrySdk
OpenTelemetrySemConv
PsrHttpMessage
PsrHttpServer

Http external standards
PsrHttpMessage
PsrHttpServer

BridgePsr external standards
PsrHttpMessage

BridgeLaravel external standards
PsrHttpMessage
LaravelHost

BridgeSymfony external standards
PsrHttpMessage
SymfonyHost

BridgeRemote external standards
PsrHttpMessage
PsrHttpClient
PsrHttpServer
```

`StorageLocal` depends on the `StorageContracts` layer and does not introduce framework or provider SDK dependencies. The `storage-local` package has no direct `evolvephp/contracts` dependency. Its internal `LocalFilesystemStorageException` implements `StorageException`, which inherits `EvolveException`, so Deptrac observes the inherited Contracts edge. The exact skip in `deptrac.baseline.yaml` permits only this class pair and does not grant the `StorageLocal` layer general access to `Contracts`. `PsrContainer` represents the approved PSR-11 interoperability layer used by Core and by Contracts for the public `ServiceDefinitionRegistrar` service-definition factory contract. Contracts remains first-party-inward and has no first-party EvolvePHP dependency; the PSR-11 reference documents the optional resolver argument accepted by component service-definition factories and does not make Contracts a container implementation. Core remains the implementation owner for the registry, frozen resolver, execution scopes and restricted registration coordinator. `PsrSimpleCache` represents the approved PSR-16 `Psr\SimpleCache` namespace used by CacheMemory as the application-facing cache contract. `PsrClock` represents the approved PSR-20 `Psr\Clock` namespace used by CacheMemory for deterministic expiration decisions. `PsrHttpMessage` represents the approved `Psr\Http\Message` namespace used by Http for PSR-7 message interfaces and PSR-17 factory interfaces, including `psr/http-message` and `psr/http-factory`, by BridgePsr for caller-owned PSR request input and resolved PSR response output, by BridgeLaravel and BridgeSymfony for caller-owned PSR request construction and delegated PSR response translation, by BridgeRemote for outer and delegated PSR requests/responses plus PSR-17 factories, by Insight for bounded incoming HTTP server diagnostics, and by Observe for explicit HTTP server tracing and outbound client propagation over caller-owned PSR messages. `LaravelHost` represents the narrow Illuminate HTTP and authentication-contract layer used only by BridgeLaravel for host request and principal translation. `SymfonyHost` represents the narrow Symfony HttpFoundation and Security Core layer used only by BridgeSymfony for host request and principal translation. `PsrHttpClient` represents the approved PSR-18 `Psr\Http\Client` namespace used by BridgeRemote for host-side remote invocation transport, by Insight for outbound HTTP client diagnostics, and by Observe for outbound client middleware. `PsrHttpServer` represents the approved PSR-15 server middleware/handler interface layer used by Http, Insight incoming server diagnostic middleware, Observe route-enrichment middleware and the BridgeRemote endpoint. `OpenTelemetryApi` represents the OpenTelemetry API and context namespaces required by Observe for provider interfaces, propagation, spans and context scopes. `OpenTelemetrySdk` represents optional SDK resource and sampler value types supported when applications install the suggested SDK. `OpenTelemetrySemConv` represents stable semantic-convention constants used by Observe, including HTTP server attributes, `error.type` and `service.name`. `McpSdk` represents the official external `Mcp\...` namespace. The optional Mcp layer accesses Core for execution scopes, SecretContracts for per-run credential resolution, PSR-18 and PSR message interfaces for its explicitly configured client, and PSR HTTP server interfaces for its JSON-only endpoint; the official SDK owns MCP protocol behavior. Full SSE streaming remains deferred.

These PSR HTTP interfaces are external interoperability standards and do not change the first-party Evolve package dependency direction. Adding `psr/http-factory` does not require a new Deptrac external namespace layer because PSR-17 factory interfaces live under `Psr\Http\Message`. PSR-18 is tracked as its own external namespace because the client interfaces live under `Psr\Http\Client`. Http still depends inward on Contracts and Core, while BridgeLaravel, BridgeSymfony and BridgeRemote use their approved external layers only at optional Bridge boundaries.

Uncovered dependencies fail. The baseline contains only the exact inherited exception pair described above; no other skipped violations are allowed. No graph is generated. New external dependency treatment requires deliberate architecture review.

## Coding Standards

PHP-CS-Fixer is the root coding-standard engine. The distributable configuration lives at:

```text
.php-cs-fixer.dist.php
```

The project style is based on PHP-FIG PER Coding Style 3.0 through PHP-CS-Fixer's `@PER-CS3x0` rule set. The floating `@PER-CS` alias is not used. The project explicitly enables alphabetical `ordered_imports` and `no_unused_imports`.

PHP-CS-Fixer checks Observe, MCP and AI `src` and `tests` directories plus the existing covered package `src` and `tests` directories and the committed skeleton PHP config/bootstrap files. The extensionless skeleton executable is protected by syntax and create-project validation rather than distorting the Finder. The root architecture tests, root documentation tests, RFCs, `vendor/` and generated caches are excluded.

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

The policy job runs on PHP 8.4. The `Policy (PHP 8.4)` job validates the root Composer manifest and lockfile before installation, installs root dependencies from the committed lockfile with `composer install`, validates the local release plan, runs package split validation, runs prerelease consumer validation, runs skeleton create-project validation, runs supply-chain checks and runs the root Architecture and Documentation policy tests through root PHPUnit 13:

```bash
php vendor/bin/phpunit --configuration phpunit.xml.dist tests/Architecture tests/Documentation
```

Those root policy tests validate EvolvePHP 2 repository governance and documentation. The preserved EvolvePHP 1 runtime is not part of the EvolvePHP 2 compatibility claim.

The required `Workspace quality (PHP 8.4)` and `Workspace quality (PHP 8.5)` context names are preserved for governance compatibility. The word `Workspace` in those protected job names no longer means a tracked `workspace/` directory exists.

The root quality matrix runs PHP 8.4 and PHP 8.5. Each matrix entry validates Composer metadata before installation, installs from `composer.lock`, and runs:

The MCP client STDIO integration suite runs in both Ubuntu matrix jobs. It exercises Linux PHP only; native Windows PHP STDIO is refused in 2.0 Beta and deferred to the 2.1 follow-up. HTTP client mode remains supported on Windows.

```bash
composer quality
```

The current CI matrix has successfully executed. Root quality passes on PHP 8.4 and PHP 8.5, and the root policy job passes on PHP 8.4 for the current tooling and package foundation. This evidence applies to the current workspace, tooling and package foundation only.

PHP 8.5 evidence for the current root quality pipeline is recorded by the CI matrix.

The EvolvePHP 2 runtime implementation is incomplete, so this is not a broader runtime-production compatibility claim.

The workflow must not run `composer update`, use a platform-requirement bypass or replace the approved aggregate quality command with duplicated individual quality commands. There is no platform-requirement bypass in CI.

Action dependencies are pinned by immutable full-SHA references. The reviewed release comments must remain beside those SHAs so future audits can connect each commit pin to its intended release tag.

## Documentation Website

The current Next manual is authored in website/docs/next/. MkDocs 1.6.1 uses its bundled theme and search; Python 3.11 and 3.13 are the documentation CI matrix, while PHP examples are checked with PHP 8.4. This toolchain is for documentation development and does not change the PHP framework runtime requirement.

On native Windows, select a Python 3.11+ launcher explicitly. For the available Python 3.13 launcher, from the repository root:

    py -3.13 -m venv website/.venv
    website/.venv/Scripts/python.exe -m pip install -r website/requirements-docs.txt
    website/.venv/Scripts/python.exe website/build_docs.py

The build wrapper runs MkDocs with --strict and copies .nojekyll into website/build/ for the intended GitHub Pages project path /evolvephp/. Run the website Python tests with EVOLVEPHP_DOCS_PHP set to an explicit PHP 8.4 executable; the documentation workflow also runs root Documentation policy tests. The separate requirements-audit.txt environment audits both exact installed Python environments. Pins are exact versions but do not contain wheel hashes, so artifact-level reproducibility remains unresolved. The build-tool-only MPL-2.0 pathspec and audit-tool certifi license declarations require policy review before acceptance.

Build validation does not publish documentation. Keep website/build/, virtual environments and caches out of source control. Future documentation snapshots must be tied to actual release tags; today's Next pages are not a historical release snapshot.

## Compatibility Evidence

EvolvePHP 2 requires PHP 8.4 as the baseline. The current CI matrix has successfully executed in GitHub Actions: the current root quality pipeline passes on PHP 8.4 and PHP 8.5, and the root policy job passes on PHP 8.4.

This verifies the current workspace, tooling and package foundation only. The preserved EvolvePHP 1 runtime is excluded, and the EvolvePHP 2 runtime implementation remains incomplete.

## Deferred Work

The following work remains deferred:

- package publication, tags and GitHub releases
- Runtime framework implementation beyond the completed HTTP package foundation
- full Module/Plugin runtime managers
- broader developer tooling beyond `module:new` and `plugin:new`
- broader Testing utilities beyond the command-output recorder
