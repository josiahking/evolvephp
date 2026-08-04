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

Production packages remain independent of `evolvephp/testing`.

PHPUnit 13 is owned by the EvolvePHP 2 workspace. It must not be added to the legacy root Composer manifest, any package manifest or production requirements.

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

Platform emulation must not be used for runtime compatibility claims. Do not use `config.platform.php`, `--ignore-platform-req=php` or `--ignore-platform-reqs` to generate the workspace lockfile or claim PHP compatibility.

## Lockfile

`workspace/composer.lock` is committed for reproducible development and CI resolution.

It was intentionally absent in Phase 2.2. Phase 2.3 creates the first workspace lockfile under real PHP 8.4 execution. It must never be handwritten or generated with `config.platform.php`.

Future changes to workspace dependencies must update the lockfile through Composer, never manually.

## Deferred Work

The following remain deferred:

- Static analysis to Phase 2.4
- Coding standards to Phase 2.4
- Dependency-boundary tooling
- GitHub Actions
- PHP 8.5 CI verification to Phase 2.6
- Security and licence scanning
- Release automation
- Framework implementation
