---
title: Monolog PSR-3 compatibility
description: Register upstream Monolog for execution-scoped PSR-3 logging with optional Observe correlation.
sidebar_position: 5
---

# Monolog PSR-3 compatibility

An application can use upstream Monolog 3 as its PSR-3 logger without an EvolvePHP logging package. Core does not install or register Monolog automatically. The application chooses the channel, handlers, formatters, log levels, buffering, sinks and retention policy. Observe correlation is optional.

## Install optional packages

Install `evolvephp/core`, `monolog/monolog:^3.0` and `psr/log:^3.0` in the application. Add `evolvephp/observe` and an OpenTelemetry SDK only when correlation is needed. During a source preview, configure a Composer path repository pointing to the local `packages/*` tree and supply explicit `2.0.0` development version mappings for the EvolvePHP packages. See [source preview](../getting-started/source-preview.md). Keep this application's dependency installation separate from the framework repository vendor.

The isolated compatibility consumers resolved Monolog 3.13.0 and PSR Log 3.0.2 on PHP 8.4.23. Review the complete lockfile, platform requirements, licenses and `composer audit` output for your own installation. Monolog is MIT licensed; EvolvePHP source packages use their own declared licenses.

## Register and use the logger

Register a `Psr\Log\LoggerInterface` binding before `freeze()`. `ServiceLifetime::Execution` creates one logger per execution. The root resolver cannot resolve it; resolve it from an `ExecutionScope`. This example uses an in-memory handler to keep the behavior visible. Replace it with application-selected handlers and formatters for production.

~~~php
<?php

use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionScope;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

$services = new ServiceRegistry();
$services->register(LoggerInterface::class, ServiceLifetime::Execution,
    static function (ExecutionScope $scope): LoggerInterface {
        $handler = new TestHandler();
        $logger = new Logger('application', [$handler]);
        $scope->registerResetParticipant('application-monolog', new class($logger) implements ResetParticipant {
            public function __construct(private Logger $logger) {}
            public function reset(): void { $this->logger->reset(); }
        });
        return $logger;
    });
$services->freeze();
~~~

Inject the PSR-3 contract into application services. The caller controls the message and context:

~~~php
<?php

use Psr\Log\LoggerInterface;

final class GreetingService
{
    public function __construct(private LoggerInterface $logger) {}

    public function greet(string $recipient): void
    {
        $this->logger->info('greeting sent', ['recipient' => $recipient]);
    }
}
~~~

Within an `ExecutionOrchestrator` operation, construct the service with `$scope->get(LoggerInterface::class)`. The operation receives the execution context and scope. If using a scope directly, close it in `finally`, including when application code throws. The orchestrator closes its scope after the operation and reports cleanup failures in its outcome.

## Add optional Observe correlation

When Observe is installed, register `ExecutionLogCorrelationInstrumentation` as an execution context attacher and use it as a `LogCorrelationProvider`. Add a Monolog processor that calls `current()->structuredFields()` **when each record is emitted**. The processor below writes only the five reserved keys to `extra`; it preserves caller `context` and unrelated `extra` fields. Removing reserved keys first prevents a prior record or another processor from supplying stale or spoofed correlation in those designated fields. If correlation is disabled or unavailable, it adds no empty placeholders.

~~~php
<?php

use Evolve\Observe\Logging\LogCorrelationProvider;
use Monolog\LogRecord;

final class ObserveFieldsProcessor
{
    private const RESERVED = [
        'trace_id' => true, 'span_id' => true, 'trace_flags' => true,
        'evolve.execution.id' => true, 'evolve.execution.kind' => true,
    ];

    public function __construct(private ?LogCorrelationProvider $provider) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $current = $this->provider?->current()->structuredFields() ?? [];
        $record->extra = array_diff_key($record->extra, self::RESERVED)
            + array_intersect_key($current, self::RESERVED);
        return $record;
    }
}
~~~

Pass `new ObserveFieldsProcessor($provider)` to Monolog's processor list when constructing the logger. The provider may be absent; the base PSR-3 consumer does not need Observe. The execution attacher and trace instrumentation must be composed in the application; see [Observe](../observability/observe.md). `trace_id`, `span_id`, and `trace_flags` come from the active OpenTelemetry span. `evolve.execution.id` and `evolve.execution.kind` come from the active EvolvePHP execution context. Outside an execution, these execution fields are absent.

The reserved-field rule covers the processor's `extra` fields. Caller-supplied `context` is preserved, including a caller key with the same name. Configure downstream formatters and consumers to treat the trusted `extra` values as authoritative if that distinction matters. This processor does not sanitize arbitrary application log content. Applications must prevent sensitive values from entering messages, context, unrelated extra fields, handlers and sinks. Do not automatically record authorization headers, cookies, session or identity data, bodies, arbitrary headers, baggage, query strings, SQL, exception messages or stack traces.

## Reset and persistent workers

Monolog's reset interface and EvolvePHP's `ResetParticipant` are separate contracts. The registration example bridges them explicitly: scope cleanup calls `Logger::reset()`, which calls `reset()` on resettable handlers and processors. The in-memory compatibility handler clears its records; the stateful compatibility processor clears its count. Review every chosen handler and processor because some may retain buffers or external connections. A reset failure is reported as an execution cleanup failure and requires worker quarantine under Core's lifecycle contract.

Sequential execution isolation is verified here. Reset does not delete already persisted log records and does not establish concurrent-worker safety. Applications own any shared handler synchronization, delivery, retention, and recovery policy.

## Verify an isolated consumer

The [consumer fixture](https://github.com/josiahking/evolvephp/blob/2.x/tools/fixtures/monolog-psr3-consumer.php) and [validator](https://github.com/josiahking/evolvephp/blob/2.x/tools/validate-monolog-compatibility.php) run against two independent Composer installations: a Core/Monolog/PSR-3 profile without Observe and a correlation profile with Observe and the OpenTelemetry SDK. From the repository root, set `EVOLVE_MONOLOG_CONSUMER_AUTOLOAD` to the chosen external consumer's `vendor/autoload.php`, then run the matching command:

~~~text
EVOLVE_MONOLOG_CONSUMER_AUTOLOAD=/path/to/base/vendor/autoload.php php tools/validate-monolog-compatibility.php --base
EVOLVE_MONOLOG_CONSUMER_AUTOLOAD=/path/to/correlation/vendor/autoload.php php tools/validate-monolog-compatibility.php --correlation
~~~

The validator fails if the environment variable does not name a real autoload file; it never falls back to the repository vendor. The base profile checks explicit registration, PSR-3 delivery, scope identity, reset, application exceptions and reset-failure quarantine. The correlation profile checks active trace and execution identifiers, reserved-field collisions, disabled and absent providers, and outside-execution logging. Both use in-memory handlers; they do not deliver telemetry or logs to an external service.
