# EvolvePHP 1 Legacy Overview

## Baseline identity

- Repository name: EvolvePHP
- Repository URL: https://github.com/josiahking/evolvephp
- Default legacy branch: `master`
- Exact audited starting commit SHA: `2da5da7866f65d314a0e2bf10b572004b3014d60`
- Audit date: 2026-07-28
- Package name: `evolvephp/evolvephp`
- Licence: BSD-3-Clause
- Current PHP requirement: `>=7.1`
- Current PHPUnit version requirement: `phpunit/phpunit` `8`
- Git tags found during audit: none

EvolvePHP 1 is a preserved legacy line. This repository state is being documented as a historical baseline before separate EvolvePHP 2 development begins.

## Composer dependencies

The current `composer.json` declares these runtime dependencies:

- `karelwintersky/monolog-pdo-handler`: `^0.2.0`
- `monolog/monolog`: `^2.0`
- `apache/log4php`: `^2.3`
- `whichbrowser/parser`: `^2.0`

The current development dependency is:

- `phpunit/phpunit`: `8`

Composer metadata also includes a `version` field with value `1.0`.

## Namespace structure

Composer PSR-4 autoloading maps:

- `EvolvePhpCore\` to `core/`
- `EvolvePhpComponent\` to `components/`
- `EvolvePhpHelper\` to `helpers/`

No `autoload-dev` namespace is configured.

## High-level directory structure

```text
evolvephp/
    components/      Application components and feature modules
    configs/         Global configuration files
    core/            Core framework classes
    helpers/         Reusable helper classes
    logs/            Runtime log directory
    public/          Assets and layout files
    tasks/           Placeholder task directory
    tests/           Test directory
    index.php        Application entry point
    route.php        Router and dispatcher
    composer.json    Package metadata and scripts
```

## Historical purpose

The README describes EvolvePHP as a lightweight, component-based PHP framework created to support structured web application development without the overhead of a large framework. It includes routing, reusable components, models, views, sessions, logging, exception management, configuration and PHPUnit testing support.

According to the current README and maintainer-provided project history, EvolvePHP was used as the foundation of Africa Global Export Market, a business-to-business export marketplace with more than 5,000 registered users.

## Current status

EvolvePHP 1 is preserved for historical reference, learning, and scoped legacy maintenance. It is not the starting point for in-place EvolvePHP 2 runtime implementation.

EvolvePHP 2 is a separate redesign effort. New EvolvePHP 2 development will not be performed as an in-place refactor of the EvolvePHP 1 legacy runtime. Any bridge, migration or compatibility work should be specified explicitly in later tasks and should preserve this audited baseline.

## Evidence sources

This overview was based on direct inspection of:

- `git status --short`
- `git branch --show-current`
- `git rev-parse HEAD`
- `git symbolic-ref refs/remotes/origin/HEAD`
- `git tag --list`
- `README.md`
- `CHANGELOG.md`
- `composer.json`
- `index.php`
- `route.php`
- `configs/`
- `core/`
- `components/`
- `helpers/`
- `tests/`
