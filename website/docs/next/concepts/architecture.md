---
title: Architecture
description: Package boundaries, contracts, and modular composition in EvolvePHP 2.
sidebar_position: 1
---

# Architecture

EvolvePHP 2 is organized as Composer packages with explicit namespace ownership. The [package map](../packages/overview.md) shows the current source packages. Applications compose the packages they need; optional infrastructure and integration packages should remain replaceable through contracts.

Dependencies point inward. Core depends on its foundational contracts and selected interoperability standards, not on optional Insight, Observe, Bridge or concrete infrastructure adapters. Production packages do not depend on Testing or DevTools. Bridge is an outward integration boundary: host-specific Laravel, Symfony, PSR and remote implementations remain outside Core.

The application direction is a modular monolith with explicit module and plugin declarations, dependency/capability graph validation, restricted registration and application-controlled enablement. Disabled components remain inert. The repository implements these foundations, while a complete production web runtime and broader application conventions are still deferred.

The local skeleton integration check exercises both a generated application module and a separately installed Composer library module. The application explicitly loads their definitions, enables their identifiers, and passes them to the Kernel. It checks module-owned namespaced messages, execution-specific locales, explicit migration planning, disabled modules, dependency ordering, and lifecycle failure cleanup. This verifies source-based integration only; first-party packages and the skeleton are not yet publicly installable.

Core's execution model is runtime-neutral. It coordinates an execution without assuming a SAPI, persistent worker, queue broker or ambient current-request global. HTTP, CLI and other consumers can compose that boundary explicitly. Read the [execution model](execution-model.md) and [service lifetimes](service-lifetimes.md) next.

The [accepted RFC index](../project/rfcs.md) explains why these boundaries were chosen. RFCs record architecture; package implementations and tests are the evidence for current behavior.
