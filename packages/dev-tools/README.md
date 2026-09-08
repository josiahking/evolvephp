# EvolvePHP DevTools

Development-time generators and tooling for EvolvePHP 2 applications.

This package remains development-only.

## Package

`evolvephp/dev-tools`

## Requirements

PHP `^8.4` with `ext-tokenizer`.

## Dependencies

`evolvephp/contracts`, `evolvephp/core`, `evolvephp/module`, `evolvephp/plugin`

## Audit Foundation

The package provides a public experimental Audit foundation under
`Evolve\DevTools\Audit`.

`AuditRunner` accepts explicitly supplied inspectors and an explicit target
project root. It performs no automatic discovery. `ComposerProjectInspector`
inspects only the target root `composer.json`; it does not scan parent
directories, nested manifests, lock files or transitive dependency graphs.
`PhpSourceCouplingInspector` discovers PHP source files below the explicit
project root and tokenizes source text with PHP's native tokenizer.

Audit treats the target project as data. It does not include target PHP files,
include the target `vendor/autoload.php`, bootstrap Laravel, Symfony, CakePHP,
Yii, EvolvePHP or custom application code, run Composer, run scripts, invoke
shell commands, load `.env`, write caches or modify target files.

The Composer inspector reports structured findings for root Composer evidence:
direct runtime and development dependencies, direct framework package evidence
for Laravel, Symfony, CakePHP, Yii and EvolvePHP, the raw root `require.php`
constraint when present, missing PHP constraint evidence, malformed Composer
evidence and `config.platform.php` review evidence.

The PHP source inspector reports structured findings for source inventory,
direct superglobal access, direct `$_SESSION` coupling, `$GLOBALS`, `global`
statements, static-state declarations and static-property access. Source paths
are reported relative to the target root with `/` separators. Findings aggregate
deterministically sorted lexical evidence and report incomplete inspection when
a source file cannot be read safely.

Raw Composer constraints are preserved exactly as declared. Audit does not solve
Composer SemVer constraints and does not claim PHP, package or framework
compatibility from raw constraints. A Composer platform override is reported as
compatibility-review evidence only; it is not proof of the actual runtime PHP
version.

Current limitations: Audit has no CLI command, JSON output, remediation,
lockfile analysis, vulnerability lookup, AST analysis, data-flow analysis,
framework bootstrap analysis, route discovery, migration scoring or Bridge
integration. Lexical PHP source evidence does not prove runtime
incompatibility, unsafe mutability or modernization feasibility.

## Generator Commands

The package provides two public experimental command adapters:

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
