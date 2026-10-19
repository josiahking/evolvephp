---
title: Execution Model
description: How Core creates, closes, and reports one unit of work.
sidebar_position: 2
---

# Execution model

An Evolve execution is one bounded unit of work. Core generates an execution identifier, selects an ExecutionKind (HTTP request, queue message, scheduled job, CLI command or worker task), creates an immutable ExecutionContext, opens an ExecutionScope, invokes the caller's operation, and closes the scope. Optional immutable context values can carry an already-resolved locale and timezone. Core does not infer them from process globals or expose an ambient current execution.

ExecutionOrchestrator returns an ExecutionOutcome. It keeps the primary handler result or throwable separate from cleanup failure. A handler failure with clean cleanup can leave the process reusable. A failed scope close or attached-context detach requires a ProcessReuseDecision of quarantine; that orchestrator then refuses further work. Instrumentation failures are reported separately and do not replace the primary outcome.

Execution scopes reset registered participants in reverse registration order, continue best-effort after a reset failure, and release scope-local state at close. This is an explicit isolation boundary, not automatic cleanup for every arbitrary object or a guarantee about third-party code.

These foundations support reasoning about sequential process reuse. They do not supply a concrete persistent web runtime, worker loop, concurrent/fiber safety, process recycler or deployment adapter. See [service lifetimes](service-lifetimes.md) and [RFC 0005](https://github.com/josiahking/evolvephp/blob/2.x/docs/rfcs/0005-request-scope-runtime-reset-and-persistent-worker-safety.md).

## Minimal execution

With the repository's Core package available to Composer, this standalone example runs one CLI unit of work. Core creates the identifier; the callback receives context and scope explicitly.

~~~php
<?php
require __DIR__ . '/vendor/autoload.php';

use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\Core\Execution\ExecutionOrchestrator;
use Evolve\Core\Execution\ExecutionScope;

$services = new ServiceRegistry();
$services->freeze();
$outcome = (new ExecutionOrchestrator($services))->execute(
    ExecutionKind::CliCommand,
    static function (ExecutionContext $context, ExecutionScope $scope): string {
        return $context->identifier()->value();
    },
);

if (!$outcome->primarySucceeded()) {
    throw $outcome->primaryThrowable();
}
if ($outcome->requiresQuarantine()) {
    throw $outcome->cleanupThrowable();
}
echo $outcome->primaryResult();
~~~

The scope closes after the callback even when it throws. Inspect primary failure and cleanup failure separately. If cleanup cannot establish isolation, quarantine the process; do not send it more work. This API is a runtime-neutral foundation, not a persistent HTTP server.
