---
title: Current Status
description: Verified development and publication status for EvolvePHP 2 Next.
sidebar_position: 2
---

# Current status

EvolvePHP 2 is **pre-release and not production-ready**. Its public APIs are experimental and may change, including breaking changes, before Stable. This page describes the current 2.x source, not the historical 2.0.0-alpha.1 tag.

## Implemented

The source repository contains PHP 8.4 runtime-neutral Core foundations for configuration, service registration, execution scopes, cleanup, component lifecycle and CLI composition. It contains HTTP middleware, routing, dispatch and response boundaries, but leaves concrete web request creation and emission to future runtime work. It also contains module/plugin foundations; development-time Audit, Doctor and adoption declarations; Bridge contracts and adapters; optional Insight and Observe packages, and explicit MCP server/client foundations. The [package map](../packages/overview.md) links to each package's own documentation.

The skeleton explicitly configures doctor and route:list and starts with an empty route collection. It is a template under source control, not a published application installer.

## Experimental

All current public APIs, Bridge integration, diagnostics and telemetry composition are experimental. An accepted architecture RFC records direction; it does not mean every described future capability is implemented. The supported PHP framework baseline is 8.4; repository CI exercises PHP 8.4 and 8.5.

## Deferred

A complete concrete SAPI/web runtime, production bootstrap, deployment scaffolding, automatic migration, cutover and rollback, broad runtime adapter support, and production service guarantees are deferred. Insight is local diagnostics; Observe requires application-owned telemetry providers and exporters.

## Unsupported or not claimed

First-party packages are **not yet independently published**. Public Packagist installation and public composer create-project installation are not available. No production readiness, LTS policy, broad host compatibility certification, automatic retry/fallback, distributed transaction, or persistent-worker concurrency guarantee is claimed. The isolated PHP 7.4 legacy remote client does not lower the PHP 8.4 Core and embedded Bridge requirement.

See [source preview](source-preview.md) for what can be done from the repository today.
