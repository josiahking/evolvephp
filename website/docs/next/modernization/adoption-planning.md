---
title: Adoption Planning
description: Declarative decisions for one bounded capability at a time.
sidebar_position: 4
status: experimental
package: evolvephp/dev-tools
---

# Adoption Planning

The DevTools adoption models record a maintainer's proposed transition for **one bounded capability**. A MigrationManifest names the capability and declares embedded or remote integration intent. Sidecar deployment is a form of remote integration, not a third mode.

The manifest records current and target route owners, data stores and owners, migration/source-of-truth states, current and target authoritative writers, compatibility requirements, and identity/security requirements. The ownership vocabulary prevents a plan from modelling two uncontrolled authoritative writers for one data declaration. An AdoptionPlan adds migration evidence, rollback evidence and measurable acceptance criteria in caller-declared order.

These objects are declarations for review. Constructing one does not discover routes, infer ownership, validate evidence, certify compatibility, prove rollback, synchronize databases, execute cutover or make migration safe. The team must verify the declared facts and operational steps against the host and target systems.

A useful review asks: Which request path is delegated? Which system may write each record at every stage? How is identity passed and checked? What evidence proves the candidate behavior? What observable condition stops or reverses traffic movement? Then choose an explicit [Bridge](bridge.md) boundary if the capability is ready to be delegated.

See the [DevTools adoption reference](https://github.com/josiahking/evolvephp/blob/2.x/packages/dev-tools/README.md).

## Minimal declaration

With DevTools available through Composer, this construction uses the current public constructors. The strings are proposed evidence and criteria; the classes do not verify them.

~~~php
<?php
require __DIR__ . '/vendor/autoload.php';

use Evolve\DevTools\Adoption\AdoptionPlan;
use Evolve\DevTools\Adoption\IntegrationMode;
use Evolve\DevTools\Adoption\MigrationManifest;

$manifest = new MigrationManifest(
    'billing',
    IntegrationMode::Embedded,
    [],
    [],
    ['Host and Evolve dependencies can run in one PHP process.'],
    ['The host supplies and verifies the authenticated user.'],
);
$plan = new AdoptionPlan(
    $manifest,
    ['Billing totals compared in staging.'],
    ['Legacy route restoration rehearsed in staging.'],
    ['No unauthorized user can view invoices.'],
);
~~~

For a real cutover, populate route ownership and data ownership declarations, including each store, migration state, and authoritative writer transition. Record tested compatibility and identity requirements plus migration and rollback evidence. A declaration alone cannot certify cutover or rollback safety.
