---
title: Service Lifetimes
description: Application, Execution, and Transient service resolution.
sidebar_position: 3
---

# Service lifetimes

Core's ServiceRegistry accepts explicit definitions and is frozen before execution scopes are created. Freezing prevents further registration; it does not construct all services.

| Lifetime | Resolution behavior |
| --- | --- |
| Application | Created on first successful root resolution and shared across execution scopes. |
| Execution | Created lazily, cached within one ExecutionScope, and released when that scope closes. |
| Transient | Constructed on each read; the caller decides whether to retain it. |

The frozen root resolver can report an Execution definition through has(), but root get() rejects it because there is no active execution scope. Application factories receive the root resolver. Execution factories receive the current scope; Transient factories receive whichever resolver requested them. This prevents an Application service from directly capturing Execution state through normal factory resolution.

The rule matters when a process handles work sequentially: retaining request, user or tenant state in an Application object could expose it to later work. Execution state belongs to a scope that is closed and reset after each unit of work. Explicit ResetParticipant registration covers additional state that must be reset. Core does not promise to detect every state leak in application or third-party code.

See the [Core package reference](https://github.com/josiahking/evolvephp/blob/2.x/packages/core/README.md) for the current API details.

## Minimal registration

With Core available through Composer, this example registers all three lifetimes and resolves them in the appropriate scope.

~~~php
<?php
require __DIR__ . '/vendor/autoload.php';

use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Psr\Container\ContainerInterface;

$services = new ServiceRegistry();
$services->register('app.name', ServiceLifetime::Application,
    static fn (ContainerInterface $root): string => 'example');
$services->register('request.id', ServiceLifetime::Execution,
    static fn (ContainerInterface $scope): object => new stdClass());
$services->register('nonce', ServiceLifetime::Transient,
    static fn (ContainerInterface $resolver): object => new stdClass());

$root = $services->freeze();
$scope = $services->createExecutionScope();
try {
    echo $root->get('app.name');
    $requestId = $scope->get('request.id');
    assert($requestId === $scope->get('request.id'));
    assert($scope->get('nonce') !== $scope->get('nonce'));
} finally {
    $scope->close();
}
~~~

An Application factory receives the root resolver. That resolver cannot directly resolve an Execution-lifetime service, preventing this normal factory path from capturing scope state. Avoid retaining a scope or scoped service in longer-lived application objects.
