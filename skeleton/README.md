# EvolvePHP Skeleton

`evolvephp/skeleton` is the first end-user application template for EvolvePHP 2. It is separate from the framework monorepo root, which remains the EvolvePHP development workspace rather than an application skeleton.

The skeleton uses the application namespace `App\` with the PSR-4 mapping `App\ => src/`. The initial `src/` directory is intentionally empty so later generator work has an authoritative target layout.

## CLI

Application CLI composition is explicit and owned by the skeleton. The skeleton shell uses the public experimental Core runtime APIs `CliApplication` and `StreamCommandOutput`, then composes `CommandRegistry`, `CommandRunner`, `ServiceRegistry` and `ExecutionOrchestrator` directly. Core remains independent of HTTP.

The command list is configured in `config/commands.php` and always registers:

- `doctor`
- `route:list`

When `evolvephp/dev-tools` is installed as a development dependency, the same
explicit config additionally registers:

- `module:new`
- `plugin:new`

The Doctor command uses the current accepted runtime and Composer-extension checks: `PhpVersionCheck` and `ComposerRequiredExtensionsCheck`. The Composer extension check reads the generated application's own `composer.json`.

The initial route configuration is explicit and empty through `config/routes.php`, which returns a `RouteCollection`. No route discovery, filesystem scanning, attributes, implicit routes or application boot magic is provided. Running the application-owned `route:list` command in a generated project reports:

```text
No routes are configured.
```

## Validation

Local prerelease create-project validation is repository-owned and available from the EvolvePHP development root:

```bash
composer release:skeleton:validate
```

That validation exercises Composer create-project against local prerelease package evidence with Packagist and Composer network access disabled. Public Packagist create-project availability is not yet claimed.

## Generators

Phase 6.4E adds development-only module and plugin starter commands through
`evolvephp/dev-tools`. `module:new Billing` creates `app/billing` under
`src/Modules/Billing/`, and `plugin:new Cache` creates `app/cache` under
`src/Plugins/Cache/`. Generated files remain application-owned and are not
auto-enabled.

The skeleton includes a small PHPUnit application test harness so generated
starter tests can run through:

```bash
composer test
```

Phase 6.4F provides the Testing package command-output recorder used by the
generator command tests; the skeleton keeps broader testing utilities deferred.

## Deferred

Command discovery, route discovery, JSON output, tables, help UI, TTY/ANSI behavior, web runtime entrypoints, controllers, middleware defaults, dotenv loading, storage defaults, deployment scaffolding and application boot magic remain deferred.
