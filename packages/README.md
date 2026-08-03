# EvolvePHP 2 Package Skeletons

This directory is the initial EvolvePHP 2 modular-monorepo package boundary accepted for Phase 2.1.

These packages are skeletons only. They contain package-level Composer manifests and empty `src/` directories, but no runtime implementation, public contracts, interfaces, classes, utilities, lifecycle code or framework behaviour.

## Initial Packages

| Package | Namespace | Status |
| --- | --- | --- |
| `evolvephp/contracts` | `Evolve\Contracts\` | Foundational public-contract boundary, no contracts implemented yet. |
| `evolvephp/core` | `Evolve\Core\` | Core orchestration boundary, no kernel or container implemented yet. |
| `evolvephp/http` | `Evolve\Http\` | HTTP boundary, no request, response, routing or middleware code implemented yet. |
| `evolvephp/module` | `Evolve\Module\` | Module SDK boundary, no descriptors or lifecycle contracts implemented yet. |
| `evolvephp/plugin` | `Evolve\Plugin\` | Plugin SDK boundary, no descriptors or lifecycle contracts implemented yet. |
| `evolvephp/testing` | `Evolve\Testing\` | Testing package boundary, no testing utilities implemented yet. |

All package manifests use PHP `^8.4`.

## Dependency Direction

The arrows represent dependency direction and not lifecycle invocation.

```text
contracts
    ^
    |
core
    ^
    |
http

contracts <- module
contracts <- plugin

testing -> contracts, core, http, module, plugin
```

`contracts` is the innermost package. `core`, `http`, `module` and `plugin` depend inward and must not depend on `testing`. The `testing` package may depend on the five initial production packages because application developers will eventually install it for development support.

No optional package families are created here. Insight, Observe, Bridge, Runtime, Deploy and other optional packages will be added only through later approved tasks.

## Current Limits

The packages have not been published.

The packages have not been installed or runtime-tested in this task.

The current root Composer manifest remains the legacy EvolvePHP 1 harness temporarily. It still exists so current repository documentation and policy tests can run while EvolvePHP 2 repository structure is introduced separately.

Phase 2.2 will establish the dedicated EvolvePHP 2 workspace Composer configuration.

Real PHP 8.4 and PHP 8.5 CI evidence is required before compatibility is claimed. Composer manifest validation under another local PHP version does not establish EvolvePHP 2 runtime compatibility.
