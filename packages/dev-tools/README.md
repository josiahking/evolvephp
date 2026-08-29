# EvolvePHP DevTools

Development-time generators and tooling for EvolvePHP 2 applications.

This package remains development-only.

## Package

`evolvephp/dev-tools`

## Requirements

PHP `^8.4`

## Dependencies

`evolvephp/contracts`, `evolvephp/core`, `evolvephp/module`, `evolvephp/plugin`

## Generator Commands

Phase 6.4E adds two public experimental command adapters:

- `Evolve\DevTools\Console\ModuleNewCommand`
- `Evolve\DevTools\Console\PluginNewCommand`

The commands are caller-registerable and receive an explicit project root. They
generate application-owned module and plugin starter files from one ASCII
StudlyCase name token. `Billing` becomes the component identifier `app/billing`;
`AuditLog` becomes `app/audit-log`.

The commands do not discover components, edit Composer manifests, execute
generated PHP, run Composer, run Git, auto-enable generated components or inspect
application state beyond the derived output paths. Invalid usage writes only to
stderr and returns exit code `2`. Existing output files are refused before any
new target is written.

## Publication Status

EvolvePHP 2 is pre-release. This package is not yet independently published, and the current canonical source is the EvolvePHP monorepo:

https://github.com/josiahking/evolvephp

## Installation

Independent Composer installation guidance will be added when package publication begins.

## Licence

BSD-3-Clause. See `LICENSE.md`.
