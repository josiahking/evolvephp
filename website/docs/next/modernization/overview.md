---
title: Modernization Overview
description: A bounded path for evolving an existing PHP application.
sidebar_position: 1
---

# Modernization overview

Modernization starts with one bounded capability and an explicit owner. EvolvePHP's current tooling helps teams gather evidence and record decisions; it does not convert an application automatically.

1. [Audit](audit.md) reads an explicit project root as data: Composer metadata, lockfile and lexical PHP source evidence. Findings are review signals.
2. [Doctor](doctor.md) checks the configured runtime and project environment. It reports PASS, WARNING or FAIL for the checks the caller actually registered.
3. [Adoption Planning](adoption-planning.md) records the selected capability, integration intent, route/data ownership, migration and rollback evidence, and acceptance criteria.
4. [Bridge](bridge.md) provides a deliberate delegation boundary for embedded or remote integration. The host keeps its outer lifecycle and the application owns security and failure policy.
5. **Modernize incrementally:** move one route, capability or data responsibility only after its ownership and rollback criteria are reviewed; inspect outcomes before the next move.

This path cannot prove compatibility or safety by itself. No stage performs automatic data migration, cutover, rollback or distributed transaction coordination. A timeout at a remote boundary does not prove that an earlier write did not happen. Maintain one authoritative writer for each data set during a transition.

Start with the [current status](../getting-started/current-status.md) to understand the pre-release limits.
