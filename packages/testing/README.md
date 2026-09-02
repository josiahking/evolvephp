# EvolvePHP Testing

Testing utilities for EvolvePHP 2 packages and applications.

This package remains development-only.

## Package

`evolvephp/testing`

## Requirements

PHP `^8.4`

## Dependencies

`evolvephp/contracts`, `evolvephp/core`, `evolvephp/http`, `evolvephp/module`, `evolvephp/plugin`

## Component Fixtures

The package provides a small Testing SDK surface for component-oriented tests.

The package provides two public experimental fixtures:

- `Evolve\Testing\Component\ComponentDefinitionFixture`
- `Evolve\Testing\Component\ComponentEntryPointFixture`

Both fixtures reuse the real Evolve component contracts from `evolvephp/contracts`.
They are intended for framework and application tests that need a generic component
definition or lifecycle participant without creating bespoke test-only classes each
time.

`ComponentDefinitionFixture` preserves a caller-supplied component identifier,
component type, graph relations, optional validator and entry-point factory. It
creates one graph declaration during construction, returns that same declaration
on every `graphDeclaration()` call, invokes the validator only when supplied and
delegates entry-point creation to the caller-supplied factory.

`ComponentEntryPointFixture` accepts optional callbacks for `register`, `boot`,
`ready` and `shutdown`. Missing callbacks are no-ops, supplied callbacks receive
the exact lifecycle objects passed by the framework and callback failures are not
wrapped by the fixture.

These APIs are experimental and may change before stable release. They do not
replace `ModuleDefinition`, `PluginDefinition`, module or plugin entry-point
interfaces, graph resolution, lifecycle orchestration, service registration
policy, discovery, developer tooling, mocks or PHPUnit assertions.

## Console Output Recorder

`Evolve\Testing\Console\RecordingCommandOutput` is a public
experimental in-memory implementation of Core's `CommandOutput` contract.

It starts empty, records normal lines through `lines()`, records error lines
through `errorLines()` and preserves write order within each stream. It is meant
for command tests and does not replace PHPUnit assertions, process execution,
TTY/ANSI handling or a full CLI testing framework.

## Publication Status

EvolvePHP 2 is pre-release. This package is not yet independently published, and the current canonical source is the EvolvePHP monorepo:

https://github.com/josiahking/evolvephp

## Installation

Independent Composer installation guidance will be added when package publication begins.

## Licence

BSD-3-Clause. See `LICENSE.md`.
