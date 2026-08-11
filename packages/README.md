# EvolvePHP 2 Packages

`packages/` contains the initial EvolvePHP 2 modular-monorepo package set.

The packages define Composer package identities, namespace ownership, dependency direction and the first Phase 3 lifecycle and configuration foundations for EvolvePHP 2. Complete runtime implementation is not yet present for the framework, and the packages are not yet published.

All package manifests require PHP `^8.4`.

## Package Map

| Package | Namespace | Responsibility |
| --- | --- | --- |
| `evolvephp/contracts` | `Evolve\Contracts\` | Foundational public-contract boundary, including the initial application lifecycle, configuration and exception contracts. |
| `evolvephp/core` | `Evolve\Core\` | Core orchestration boundary, including the initial minimal application lifecycle kernel and array-backed configuration implementation. |
| `evolvephp/http` | `Evolve\Http\` | HTTP boundary for later request, response, routing and middleware work. |
| `evolvephp/module` | `Evolve\Module\` | Module SDK boundary for later descriptors and lifecycle contracts. |
| `evolvephp/plugin` | `Evolve\Plugin\` | Plugin SDK boundary for later descriptors and lifecycle contracts. |
| `evolvephp/testing` | `Evolve\Testing\` | Testing-support boundary for later developer testing utilities. |

## Dependency Direction

The arrows in dependency diagrams represent dependency direction, not lifecycle invocation.

The package graph follows an inward dependency principle:

- `contracts` is the innermost package.
- `core`, `module` and `plugin` depend inward on `contracts`.
- `http` depends inward on `contracts` and `core`.
- `testing` may depend on the five production packages for development support.

There is no production dependency on Testing.

No optional package families are present here. Insight, Observe, Bridge, Runtime, Deploy and other optional packages require later approved work.

## Current Limitations

Contracts and Core now contain the first Phase 3 lifecycle and configuration foundations: a narrow application boot/shutdown contract, public lifecycle and configuration exception catch boundaries, read-only configuration lookup contracts, a small validation contract, an immutable Core array-backed configuration implementation and deterministic boot-time validation before readiness.

Configuration values are application-supplied scalar, null or recursive-array data. Dot-path lookup is supported for associative maps, missing values remain distinct from explicit null values, and validator failure makes that kernel instance terminal; construct a new kernel to retry corrected startup.

HTTP, Module, Plugin and Testing runtime source remains intentionally empty in this slice. EvolvePHP 2 does not yet provide HTTP handling, container or PSR-11 integration, environment or dotenv loading, configuration files, execution scopes, reset handling, module/plugin runtime, console behavior, telemetry or production-ready framework runtime behavior.

The EvolvePHP 2 Composer workspace resolves and validates these local packages. See [../workspace/README.md](../workspace/README.md) for setup, testing, quality commands, lockfile, static-analysis, coding-standard and architecture-boundary policy.
