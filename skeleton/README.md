# EvolvePHP Skeleton

`evolvephp/skeleton` is the first end-user application template for EvolvePHP 2. It is separate from the framework monorepo root, which remains the EvolvePHP development workspace rather than an application skeleton.

The skeleton uses the application namespace `App\` with the PSR-4 mapping `App\ => src/`. The initial `src/` directory is intentionally empty so later generator work has an authoritative target layout.

The production manifest includes `evolvephp/view` for explicit application-owned server-rendered templates. The native PHP renderer is the zero-third-party-dependency default. Optional `evolvephp/view-twig` and `evolvephp/view-blade` adapters require explicit installation and selection; neither is installed by default or required by Insight. View source registration remains application configuration; the skeleton has no default view roots or HTTP view helper.

The manifest also includes `evolvephp/i18n`. Applications choose supported locales and register message sources explicitly; the skeleton supplies no default locale list, automatic negotiation or template translator global. The ordinary PHP-array translation path does not require Intl.

For current Alpha onboarding, see [Alpha getting started](../docs/alpha/getting-started.md) and [Application foundations](../docs/alpha/application-foundations.md). Those guides describe the skeleton as the accepted application template while package publication and public create-project installation remain unclaimed.

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

Development-only module and plugin starter commands are provided through
`evolvephp/dev-tools`. `module:new Billing` creates `app/billing` under
`src/Modules/Billing/`, and `plugin:new Cache` creates `app/cache` under
`src/Plugins/Cache/`. Generated files remain application-owned and are not
auto-enabled.

The skeleton includes a small PHPUnit application test harness so generated
starter tests can run through:

```bash
composer test
```

The Testing package provides the command-output recorder used by the
generator command tests; the skeleton keeps broader testing utilities deferred.

## Explicit module composition

The module:new Billing command creates an application-owned entry point and a module.php definition. The generated CLI does not load or enable the definition automatically. Application code can load it explicitly, pass it to ComponentBootstrapper, configure evolve.components.enabled with app/billing, and boot and shut down ApplicationKernel. The repository's offline skeleton validation exercises that path and also installs an independent library module through Composer. The library module remains under vendor and has its own namespace.

Place this in a PHP file at the generated application root after running module:new Billing:

<!-- module-sdk-example -->
```php
<?php

declare(strict_types=1);

use Evolve\Core\ApplicationKernel;
use Evolve\Core\Component\ComponentBootstrapper;
use Evolve\Core\Configuration\ArrayConfiguration;
use Evolve\Module\ModuleDefinition;

require __DIR__ . '/vendor/autoload.php';

$definition = require __DIR__ . '/src/Modules/Billing/module.php';
if (!$definition instanceof ModuleDefinition) {
    throw new RuntimeException('Expected a module definition.');
}

$kernel = new ApplicationKernel(
    new ArrayConfiguration(['evolve' => ['components' => ['enabled' => ['app/billing']]]]),
    components: new ComponentBootstrapper([$definition]),
);
$kernel->boot();
try {
    // Run application work.
} finally {
    $kernel->shutdown();
}
```

Modules can expose message catalogs and migration definitions for the application to register explicitly. The application supplies execution locale to I18n and controls migration planning and execution. Booting a module does not run migrations.

## Deferred

Command discovery, route discovery, JSON output, tables, help UI, TTY/ANSI behavior, web runtime entrypoints, controllers, middleware defaults, dotenv loading, storage defaults, deployment scaffolding and application boot magic remain deferred.
