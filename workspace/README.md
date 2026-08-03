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

Production packages remain independent of `evolvephp/testing`.

## Local Commands

Validate the workspace Composer schema:

```bash
composer --working-dir=workspace validate --strict
```

Under the current PHP 8.3 local environment, dependency-solver verification may be run as a graph-only dry run:

```bash
composer --working-dir=workspace update \
  --dry-run \
  --no-install \
  --no-interaction \
  --ignore-platform-req=php
```

This command verifies Composer's dependency graph only. It does not install packages, it does not create the lockfile, and it does not prove PHP 8.4 or 8.5 runtime compatibility.

`--ignore-platform-req=php` must not be used for production installation or compatibility claims.

## Lockfile

`workspace/composer.lock` is intended to be committed for reproducible development and CI resolution.

It is intentionally absent in Phase 2.2. The first workspace lockfile must be generated under real PHP 8.4 execution, reviewed, and committed by a later task. It must never be handwritten or generated with `config.platform.php`.

Future changes to workspace dependencies must update the lockfile through Composer, never manually.

## Deferred Work

The following remain deferred:

- PHPUnit workspace setup
- Package test suites
- Static analysis
- Coding standards
- Dependency-boundary tooling
- GitHub Actions
- PHP 8.4/8.5 runtime verification
- Security and licence scanning
- Release automation
- Framework implementation
