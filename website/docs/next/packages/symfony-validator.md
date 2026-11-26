---
title: Symfony Validator compatibility
description: Validate application DTOs with upstream Symfony Validator through explicit EvolvePHP services.
sidebar_position: 6
---

# Symfony Validator compatibility

Symfony Validator is an optional upstream integration. EvolvePHP Core does not install, configure, or invoke it automatically. Your application owns DTO constraints, validation groups, error mapping and HTTP responses. No EvolvePHP validation package, Symfony FrameworkBundle, or Symfony Bridge is required.

## Install in the application

Require `evolvephp/core` and `symfony/validator:^7.4.23` in the consuming application. The lower bound excludes versions affected by [GHSA-4qpf-57w4-m3wc](https://github.com/symfony/symfony/security/advisories/GHSA-4qpf-57w4-m3wc). Symfony Validator is MIT licensed. Review the resolved lockfile, transitive dependencies, platform requirements, licenses and `composer audit` output for each application. The isolated PHP 8.4.23 consumer resolved Symfony Validator 7.4.24 and passed the behavioral compatibility check. This guide does not certify 8.x or other 7.4 patch versions.

For a source preview, point a Composer path repository at this worktree's `packages/*` and map `evolvephp/core` and `evolvephp/contracts` to `2.0.x-dev`. See [source preview](../getting-started/source-preview.md). Keep the application vendor directory separate from the EvolvePHP repository.

## Register and use the validator

Register the upstream interface before freezing the registry. Its factory enables attribute mapping and creates one validator for each execution scope.

~~~php
<?php

use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

$services = new ServiceRegistry();
$services->register(
    ValidatorInterface::class,
    ServiceLifetime::Execution,
    static fn (): ValidatorInterface => Validation::createValidatorBuilder()
        ->enableAttributeMapping()
        ->getValidator(),
);
$services->freeze();

$scope = $services->createExecutionScope();
try {
    $validator = $scope->get(ValidatorInterface::class);
    // Pass $validator to an application service constructor.
} finally {
    $scope->close();
}
~~~

The root resolver cannot resolve an execution-lifetime validator. Construct application services with `ValidatorInterface`; do not locate it inside domain objects. Scope closure belongs in `finally`, including when input is invalid or application code throws.

## Validate typed DTOs

The [application fixture](https://github.com/josiahking/evolvephp/blob/2.x/tools/fixtures/symfony-validator-consumer.php) defines `OrderRequest` and `ShippingAddress`. It uses `NotBlank`, `Length`, `Email`, and `PositiveOrZero` for scalar values. `#[Valid]` traverses the nested address; `All` checks each tag, so an invalid second tag has path `tags[1]`. `AllowedRegion` and `AllowedRegionValidator` are application-owned custom constraint classes. Valid inputs produce no violations; invalid values produce field-specific violations.

Validation operates on already constructed typed DTOs. The fixture's `makeOrderRequest()` rejects missing fields and malformed types before calling Symfony Validator; it does not deserialize JSON or convert types. The `Default` group checks ordinary DTO constraints. Separate `create` and `update` groups apply their operation-specific rules to `operationCode`; pass the intended group explicitly when validating. Group selection does not implicitly combine other groups.

## Map errors for HTTP 422

The application can convert a `ConstraintViolationListInterface` to a response with this stable shape:

~~~json
{"status":422,"errors":[{"field":"email","code":"email"}]}
~~~

`safeValidationResponse()` in the fixture maps only known paths and constraint types. An unknown path becomes `form`; an unknown constraint becomes `invalid`. It never copies raw invalid values, arbitrary violation messages, complete violation objects, stack traces, or internal configuration. The caller must distinguish DTO validation failures from infrastructure failures. EvolvePHP does not generate HTTP 422 responses automatically. Applications must still decide their own response envelope and authentication or authorization policy.

## Run the independent consumer check

Install the two direct requirements in an isolated application consumer, then point `EVOLVE_VALIDATOR_CONSUMER_AUTOLOAD` at that consumer's `vendor/autoload.php` and run `php tools/validate-symfony-validator-compatibility.php` from the repository root. The validator has no repository-vendor fallback. It checks dependency provenance, nested and collection paths, groups, custom constraints, safe errors, and sequential execution scopes without a network service, database or Symfony application.

The source fixture and executable checker are examples, not a production HTTP pipeline. This check does not establish concurrent-worker safety, persistent-worker production behavior, automatic middleware, or validation of arbitrary user input before typed construction.