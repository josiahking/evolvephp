---
title: Evolve Doctor
description: Configured runtime and project environment checks.
sidebar_position: 3
status: experimental
package: evolvephp/core
---

# Evolve Doctor

DoctorRunner executes only the checks explicitly supplied at composition time, in registration order. A DoctorFinding has a stable identifier, a PASS, WARNING or FAIL status, a message and an optional remediation hint. A report is successful unless a FAIL finding exists; warnings still allow success.

Current check implementations cover PHP version, required runtime extensions, Composer-declared ext-* requirements, optional programmatic environment-variable presence checks, and optional writable-path checks. These are separate checks, so the presence of a check class does not mean every application runs it.

The repository skeleton's doctor shell command currently registers only PhpVersionCheck and ComposerRequiredExtensionsCheck against the generated application's composer.json. The command prints each finding and remediation, returns exit 0 for a successful report and exit 1 when the report has a FAIL. It rejects arguments/options with exit 2. It does not automatically add environment-variable or writable-path checks.

Doctor does not solve the full Composer dependency graph, validate Bridge or route ownership, certify persistent workers, or certify production readiness. Run it alongside [Audit](audit.md) and explicit [Adoption Planning](adoption-planning.md), not as a migration pass/fail certificate.

See the [Core Doctor reference](https://github.com/josiahking/evolvephp/blob/2.x/packages/core/README.md) and [skeleton command composition](https://github.com/josiahking/evolvephp/blob/2.x/skeleton/config/commands.php).

## Command and composition

The Core command name is `doctor`. In the repository skeleton, run:

~~~bash
vendor/bin/evolve doctor
~~~

The skeleton currently supplies PHP version and Composer top-level `require` `ext-*` checks. A host may programmatically supply runtime extension, environment-variable presence, and writable-path checks to `DoctorRunner`; these are not automatically part of the default shell command. Each finding is PASS, WARNING, or FAIL. PASS and WARNING reports exit 0; a FAIL report exits 1. Unsupported command arguments exit 2.
