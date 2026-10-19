---
title: Evolve Audit
description: Read-only Composer, lockfile, and lexical PHP source evidence.
sidebar_position: 2
status: experimental
package: evolvephp/dev-tools
---

# Evolve Audit

Evolve Audit is a development-time, read-only inspection of an **explicit target root**. The standalone package binary accepts a target path and renders deterministic text by default or JSON schema version 1:

    evolve-audit TARGET_ROOT [--format=text|--format=json]

This is a CLI surface supplied by the DevTools package, not a claim that the package is publicly installable today. The binary runs on its own PHP 8.4 runtime; it treats the target project as data and does not require the target to run PHP 8.4.

Audit reads only the target root composer.json and composer.lock. It reports direct dependency, PHP/platform, autoload, resolved package, extension, plugin and lockfile metadata as evidence. Its PHP source inspectors discover .php files below the target root while excluding VCS and vendor trees. Native tokenization reports namespaces, named declarations, direct superglobal/session/global/static coupling and a bounded catalogue of runtime-call hazards. The evidence is lexical; it does not perform control-flow or data-flow analysis.

Audit does **not** bootstrap target code, include target autoloaders, load .env, run Composer, execute scripts or mutate files. Warning and Risk findings are review evidence and do not themselves make the CLI fail. Findings do not certify dependency compatibility, Bridge compatibility, migration readiness, runtime safety, security or a capability boundary. Audit does not propose an automatic migration plan or remediation.

Use the result to focus human review, then record decisions in [Adoption Planning](adoption-planning.md). See the [DevTools package reference](https://github.com/josiahking/evolvephp/blob/2.x/packages/dev-tools/README.md) for the full current evidence catalogue.

## CLI

From a source workspace with DevTools dependencies installed, pass an explicit target directory:

~~~bash
php packages/dev-tools/bin/evolve-audit path/to/legacy-project
php packages/dev-tools/bin/evolve-audit path/to/legacy-project --format=text
php packages/dev-tools/bin/evolve-audit path/to/legacy-project --format=json
~~~

The package binary is named `evolve-audit`; these source-workspace commands do not imply public package publication. JSON output has this top-level schema shape:

~~~json
{"schema_version": 1, "findings": []}
~~~

The empty array illustrates shape only. Real projects may produce findings. Audit treats the target as data: it does not include target PHP, bootstrap target autoload or framework code, run Composer, load .env, or mutate the target. It performs no vulnerability lookup or SemVer solve and does not certify compatibility or migration readiness.
