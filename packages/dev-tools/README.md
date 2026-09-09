# EvolvePHP DevTools

Development-time generators and tooling for EvolvePHP 2 applications.

This package remains development-only.

## Package

`evolvephp/dev-tools`

## Requirements

PHP `^8.4` with `ext-tokenizer`.

## Dependencies

`evolvephp/contracts`, `evolvephp/core`, `evolvephp/module`, `evolvephp/plugin`

## Adoption Planning

The package provides public experimental adoption-planning models under
`Evolve\DevTools\Adoption`.

An `AdoptionPlan` records explicit maintainer migration decisions for one
bounded capability at a time. Its `MigrationManifest` records the selected
capability, whether the capability is intended for embedded or remote
integration, route ownership transitions, data ownership transitions,
migration/source-of-truth states, compatibility requirements and
identity/security requirements. Sidecar deployment is represented as a
deployment form of remote mode rather than a separate integration mode.

Route declarations record current and target route owners. Data declarations
record current and target stores, current and target authoritative writers,
current and target migration states and any declared temporary synchronization.
The ownership vocabulary prevents modelling two uncontrolled authoritative
writers for the same data declaration.

The plan records migration evidence, rollback evidence and measurable
acceptance criteria in caller-declared order. These are declarations for
planning and review; construction does not certify that the evidence is valid,
rollback is possible, acceptance criteria have been met or cutover is safe.

Current limitations: adoption planning has no automatic plan generation from
Audit, route discovery, ownership inference, migration scoring, compatibility
certification, migration-readiness certification, Bridge implementation, Bridge
protocol, adapter, database synchronization, cutover execution or rollback
execution.

## Audit Foundation

The package provides a public experimental Audit foundation under
`Evolve\DevTools\Audit`.

`AuditRunner` accepts explicitly supplied inspectors and an explicit target
project root. It performs no automatic discovery. `ComposerProjectInspector`
inspects only the target root `composer.json`; it does not scan parent
directories, nested manifests, lock files or transitive dependency graphs.
`ComposerLockInspector` inspects only the target root `composer.lock` for
resolved lockfile evidence. `PhpSourceCouplingInspector` discovers PHP source
files below the explicit project root and tokenizes source text with PHP's
native tokenizer. `PhpSourceStructureInspector` reports lexical namespace and
named-declaration structure from those discovered PHP source files.

Audit treats the target project as data. It does not include target PHP files,
include the target `vendor/autoload.php`, bootstrap Laravel, Symfony, CakePHP,
Yii, EvolvePHP or custom application code, run Composer, run scripts, invoke
shell commands, load `.env`, write caches or modify target files.

The Composer inspector reports structured findings for root Composer evidence:
direct runtime and development dependencies, direct framework package evidence
for Laravel, Symfony, CakePHP, Yii and EvolvePHP, the raw root `require.php`
constraint when present, missing PHP constraint evidence, malformed Composer
evidence and `config.platform.php` review evidence.
It also reports root `autoload` and `autoload-dev` metadata for PSR-4, PSR-0,
classmap and files sections as raw Composer evidence. Runtime PSR-4 namespaces
and runtime autoload files may produce modernization review signals, while
development autoload metadata remains evidence only.

The Composer lockfile inspector reports deterministic read-only evidence for
locked runtime and development package inventory, raw locked versions, locked
package requirement edges, PHP, extension, library and Composer platform
requirements, Composer-plugin package metadata and lockfile-declared abandoned
package metadata. Runtime and development lockfile sections remain separate,
and malformed entries produce incomplete evidence without erasing valid sibling
package evidence.

The PHP source inspector reports structured findings for source inventory,
direct superglobal access, direct `$_SESSION` coupling, `$GLOBALS`, `global`
statements, static-state declarations, static-property access and a bounded
catalogue of runtime-call hazard review evidence. The runtime-call catalogue is
limited to direct lexical evidence for `session_start()`, selected
process-global mutation calls, response/output side-effect calls and constructs,
process-lifetime callback registration, process termination, `eval` and
include/require constructs. Source paths are reported relative to the target
root with `/` separators. Findings aggregate deterministically sorted lexical
evidence and report incomplete inspection when a source file cannot be read
safely.

The PHP source structure inspector reports namespace declarations and named
classes, interfaces, traits, enums and functions using PHP's native tokenizer.
Non-empty namespaces with named declarations may produce namespace-group review
signals, and global named declarations or files with multiple namespace
declarations are reported for human review.

Raw Composer constraints and raw locked versions are preserved exactly as
declared. Audit does not solve Composer SemVer constraints, execute Composer,
query live vulnerability or package registries, verify lock freshness or
content hashes, certify `composer.json` to `composer.lock` consistency, or claim
PHP, package, framework, lockfile or Bridge compatibility from raw evidence. A
Composer platform override is reported as compatibility-review evidence only;
it is not proof of the actual runtime PHP version.

Audit can be used through the standalone `evolve-audit` binary against an
explicit existing PHP project root without migrating that project to EvolvePHP
first:

```sh
evolve-audit <target-root>
evolve-audit <target-root> --format=text
evolve-audit <target-root> --format=json
```

Text output is the default. JSON output uses schema version `1` and contains
the stable top-level shape:

```json
{
    "schema_version": 1,
    "findings": []
}
```

Warning and Risk findings are review evidence and do not themselves make the
command fail; invalid command usage and invalid target roots return usage
errors. The tool requires its own PHP `^8.4` runtime, while the target project
may use an older PHP version because target code is read as source text and
metadata only.

Current limitations: Audit has no remediation, vulnerability lookup, AST
analysis, control-flow analysis, data-flow analysis, framework bootstrap
analysis, route discovery, data ownership discovery, migration scoring,
automatic migration plan, compatibility certification or Bridge integration.
Autoload mappings and namespaces are structural review hints, not proven module
or capability boundaries. Lockfile evidence is recorded dependency metadata
only; it does not prove dependency compatibility, freshness or security status.
Lexical PHP source evidence does not prove runtime incompatibility,
persistent-worker unsafety, Bridge compatibility status, unsafe mutability,
modernization feasibility, migration readiness or whether a detected occurrence
actually executes. Include and require evidence records the construct and source
line only; Audit does not resolve, classify, inspect or follow included paths.

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
