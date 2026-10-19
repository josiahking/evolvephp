---
title: EvolvePHP 2
description: Start with the current EvolvePHP 2 developer manual.
sidebar_position: 1
---

# EvolvePHP 2

This is the current **Next** developer manual for the 2.x source.

EvolvePHP 2 is a pre-release PHP framework project for modular applications and incremental modernization of existing systems. Its package boundaries and explicit contracts make it possible to keep an application modular while choosing infrastructure and integration points deliberately. The design starts with a modular monolith; a team can integrate with an existing host and extract a bounded capability when the evidence supports it.

The current 2.x source implements runtime-neutral foundations, HTTP composition, module and plugin foundations, developer diagnostics, Bridge adapters, and optional observability packages. It does **not** yet provide a complete production web runtime or public application installation through independently published first-party packages. Experimental public APIs may change before Stable. EvolvePHP 2 is not production-ready.

## Choose a path

- **New application:** read [current status](getting-started/current-status.md), [requirements](getting-started/requirements.md), [source preview](getting-started/source-preview.md), and [architecture](concepts/architecture.md). The application skeleton is source evidence for the intended composition; it is not yet a public create-project installation.
- **Existing application:** follow [Audit → Doctor → Adoption Planning → Bridge → incremental modernization](modernization/overview.md). Each stage produces evidence or an explicit boundary. None automatically migrates an application.

For implementation detail, use the [package map](packages/overview.md). For design rationale, see the [accepted RFCs](project/rfcs.md).
