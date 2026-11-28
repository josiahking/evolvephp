# EvolvePHP Skeleton

`evolvephp/skeleton` is the first end-user application template for EvolvePHP 2. It is separate from the framework monorepo root, which remains the EvolvePHP development workspace rather than an application skeleton.

The skeleton uses the application namespace `App\` with the PSR-4 mapping `App\ => src/`. The initial `src/` directory is intentionally empty so later generator work has an authoritative target layout.

The production manifest includes `evolvephp/view` for explicit application-owned server-rendered templates. The native PHP renderer is the zero-third-party-dependency default. Optional `evolvephp/view-twig` and `evolvephp/view-blade` adapters require explicit installation and selection; neither is installed by default or required by Insight. View source registration remains application configuration; the skeleton has no default view roots or HTTP view helper.

The manifest also includes `evolvephp/i18n`. Applications choose supported locales and register message sources explicitly; the skeleton supplies no default locale list, automatic negotiation or template translator global. The ordinary PHP-array translation path does not require Intl.

For historical Alpha source-preview context, see [Alpha getting started](https://github.com/josiahking/evolvephp/blob/2.x/docs/alpha/getting-started.md) and [Application foundations](https://github.com/josiahking/evolvephp/blob/2.x/docs/alpha/application-foundations.md). All 34 first-party framework packages have publicly verified Beta distributions. The standalone `evolvephp/skeleton:2.0.0-beta.1` project is also publicly available. These publications are separate from a coordinated framework-wide Beta release announcement.

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

That validation exercises Composer create-project using isolated local Beta-labelled package fixtures with Packagist and Composer network access disabled. It preserves the verified CLI, generator, independent module and production `--no-dev` checks. It also confirms that Audit Log, Webhooks and API Keys are absent from the default application, installs them only after an explicit application-owned Composer opt-in, runs their installed smoke fixtures, and checks explicit module enablement and no-dev retention. Registration and boot leave migrations and network delivery under application control. This offline test does **not** prove that the separately published skeleton is available from Packagist.

Audit Log, Webhooks and API Keys are source-only modules in the current 37-package source inventory. They were not among the 34 originally published framework Beta packages. The local synthetic Beta tags used by validation are not public releases; do not treat them as public Composer installation targets.

## Public Beta installation

The skeleton is a separate Composer `project`, not an additional framework runtime package. `evolvephp/skeleton:2.0.0-beta.1` is publicly available through Composer. Its published tag, Packagist metadata and external `create-project` installation have been validated:

```bash
composer create-project evolvephp/skeleton my-app 2.0.0-beta.1
cd my-app
php bin/evolve doctor
php bin/evolve route:list
```

External consumer validation covered public installation, production-only dependencies, full development dependencies, Composer autoload, `doctor` and `route:list`. This does not establish that framework test suites, static analysis, security review, performance budgets or all runtime environments passed. The coordinated framework-wide Beta release has not been announced. EvolvePHP 2 remains experimental, pre-release and not production-ready.

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
