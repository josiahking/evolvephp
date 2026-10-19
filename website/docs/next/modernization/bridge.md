---
title: Evolve Bridge
description: Explicit embedded and remote integration boundaries.
sidebar_position: 5
status: experimental
---

# Evolve Bridge

Bridge delegates a bounded capability from a host application to EvolvePHP. It is an integration and execution boundary, **not** a source converter or migration engine. The host retains process startup, routing decisions, container ownership, request acceptance and final response emission. Evolve owns the delegated execution after the host selects it.

## Embedded mode

The repository contains Bridge contracts, a PSR embedded adapter and host adapters for Laravel and Symfony. Embedded mode runs in the same process, so host and Evolve must share a compatible PHP runtime and Composer dependency graph. Adapter existence is not a blanket compatibility certification for every host version or application.

## Remote mode

The remote package provides a versioned HTTP JSON protocol, client and server foundations. Sidecar deployment is still remote mode and crosses a process/dependency boundary. It can separate legacy PHP and dependency graphs, but adds network failure, endpoint trust and identity/security decisions. The separately maintained legacy HTTP client has PHP 7.4 evidence for **remote protocol compatibility only**; it cannot run EvolvePHP Core or embedded Bridge.

A timeout cannot establish whether a remote write took effect. Retrying writes therefore requires deliberate idempotency and reconciliation policy. Authentication, authorization, timeouts, retries, fallback and endpoint trust are application or deployment responsibilities. Bridge does not provide automatic retries/fallback, shared sessions or cookies, distributed transactions, cutover or rollback.

Plan one authoritative writer per data set and explicit route ownership before moving traffic. Read the [planning model](adoption-planning.md) and [RFC 0006](https://github.com/josiahking/evolvephp/blob/2.x/docs/rfcs/0006-evolve-bridge-and-incremental-modernisation.md). The [Bridge package references](https://github.com/josiahking/evolvephp/tree/2.x/packages) contain current adapter details.

## PSR embedded invocation

The host must first compose a ready `EmbeddedBridgeAdapter` with its `HttpKernel`, `ExecutionOutcomeResponseResolver`, and `ReadinessCheck`. Given that adapter and a host-created PSR-7 server request, invocation uses the current public boundary:

~~~php
<?php
use Evolve\Bridge\Contracts\BridgeContext;
use Evolve\Bridge\Psr\EmbeddedBridgeAdapter;
use Psr\Http\Message\ServerRequestInterface;

/** @var EmbeddedBridgeAdapter $adapter */
/** @var ServerRequestInterface $request */
$context = new BridgeContext('request-1', 'correlation-1');
$result = $adapter->invoke($request, $context);

if ($result->requiresQuarantine()) {
    // The host must retire the affected process before accepting more work.
}
if ($result->response() !== null) {
    // The host emits this PSR response through its own response emitter.
}
~~~

This is the invocation boundary, not standalone application bootstrap. Laravel and Symfony adapters wrap the PSR embedded path for their host request/response systems; verify host version and dependency compatibility in the actual application. Remote Bridge separates runtime dependencies through a versioned HTTP JSON protocol. A remote timeout does not prove a write had no effect: use one authoritative writer per data boundary and deliberate idempotency before retrying. Bridge does not create a distributed transaction, automatic uncertain-write fallback, migration, cutover, or rollback.
