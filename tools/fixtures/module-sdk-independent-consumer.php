<?php

declare(strict_types=1);

use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Core\ApplicationKernel;
use Evolve\Core\Component\ComponentBootstrapper;
use Evolve\Core\Configuration\ArrayConfiguration;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionContext;
use Evolve\Core\Execution\ExecutionContextValues;
use Evolve\Core\Execution\ExecutionIdentifier;
use Evolve\Core\Execution\ExecutionKind;
use Evolve\I18n\ExecutionLocalizationContextFactory;
use Evolve\I18n\FilesystemMessageCatalog;
use Evolve\I18n\LocalizationPolicy;
use Evolve\I18n\MessageName;
use Evolve\I18n\MessageSource;
use Evolve\Migration\AppliedMigration;
use Evolve\Migration\MigrationHistoryStore;
use Evolve\Migration\MigrationIdentifier;
use Evolve\Migration\MigrationOwner;
use Evolve\Migration\MigrationPlanner;
use Evolve\Migration\MigrationPlanStatus;
use Evolve\Migration\MigrationRegistry;
use Evolve\Module\ModuleDefinition;
use Evolve\Module\ModuleDescriptor;

function demand(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function writeFixture(string $path, string $contents): void
{
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('Cannot create fixture directory.');
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Cannot write fixture file.');
    }
}

function preparePackage(string $directory): void
{
    if (file_exists($directory)) {
        throw new RuntimeException('Package target already exists.');
    }
    writeFixture($directory . '/composer.json', <<<'JSON'
{
    "name": "acme/module-sdk-billing",
    "version": "1.0.0",
    "type": "library",
    "license": "BSD-3-Clause",
    "require": {
        "php": "^8.4",
        "evolvephp/contracts": "^2.0",
        "evolvephp/module": "^2.0",
        "evolvephp/i18n": "^2.0",
        "evolvephp/migration": "^2.0"
    },
    "autoload": {
        "psr-4": {
            "Acme\\ModuleSdk\\": "src/"
        }
    }
}
JSON);
    writeFixture($directory . '/src/BillingModule.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\ModuleSdk;

use Evolve\Contracts\Component\ComponentBootContext;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Module\Module;
use RuntimeException;

final class BillingModule implements Module
{
    /** @var list<string> */
    public static array $events = [];
    public static bool $failBoot = false;
    public static int $migrationRuns = 0;

    private static function record(string $event): void
    {
        self::$events[] = $event;
        $log = getenv('EVOLVE_MODULE_SDK_EVENT_LOG');
        if ($log !== false) {
            file_put_contents($log, 'acme:' . $event . PHP_EOL, FILE_APPEND);
        }
    }
    public function register(ServiceDefinitionRegistrar $registrar): void
    {
        self::record('register');
        $registrar->registerApplication('acme.billing', static fn(): string => 'installed');
    }

    public function boot(ComponentBootContext $context): void
    {
        self::record('boot');
        if ($context->services()->get('acme.billing') !== 'installed') {
            throw new RuntimeException('Registered service unavailable at boot.');
        }
        $context->deferFailureCleanup(static function (): void { self::record('cleanup'); });
        if (self::$failBoot) {
            throw new RuntimeException('Intentional module boot failure.');
        }
    }

    public function ready(): void
    {
        self::record('ready');
    }

    public function shutdown(): void
    {
        self::record('shutdown');
    }
}
PHP);
    writeFixture($directory . '/module.php', <<<'PHP'
<?php

declare(strict_types=1);

use Acme\ModuleSdk\BillingModule;
use Evolve\Contracts\Component\ComponentDependency;
use Evolve\Contracts\Component\ComponentDependencyKind;
use Evolve\Contracts\Component\ComponentGraphRelations;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Module\ModuleDefinition;
use Evolve\Module\ModuleDescriptor;

return new ModuleDefinition(
    new ModuleDescriptor(
        new ComponentIdentifier('acme/billing'),
        'Acme Billing',
        2,
        new ComponentGraphRelations([
            new ComponentDependency(new ComponentIdentifier('app/billing'), ComponentDependencyKind::Required),
        ]),
    ),
    BillingModule::class,
);
PHP);
    writeFixture($directory . '/lang/en.php', "<?php return ['greeting' => 'Hello'];\n");
    writeFixture($directory . '/lang/fr.php', "<?php return ['greeting' => 'Bonjour'];\n");
    writeFixture($directory . '/migrations.php', <<<'PHP'
<?php

declare(strict_types=1);

use Acme\ModuleSdk\BillingModule;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Migration\MigrationAction;
use Evolve\Migration\MigrationDefinition;
use Evolve\Migration\MigrationIdentifier;
use Evolve\Migration\MigrationOwner;

return [
    new MigrationDefinition(
        MigrationOwner::module(new ComponentIdentifier('acme/billing')),
        new MigrationIdentifier('create-ledger'),
        10,
        str_repeat('a', 64),
        MigrationAction::fromCallable(static function (): void { BillingModule::$migrationRuns++; }),
    ),
];
PHP);
}

function instrumentGeneratedModule(string $application): void
{
    $path = $application . '/src/Modules/Billing/BillingModule.php';
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Generated Billing module is missing.');
    }
    $methods = [
        'register(ServiceDefinitionRegistrar $registrar)' => 'app:register',
        'boot(ComponentBootContext $context)' => 'app:boot',
        'ready()' => 'app:ready',
        'shutdown()' => 'app:shutdown',
    ];
    foreach ($methods as $signature => $event) {
        $old = 'public function ' . $signature . ': void {}';
        $new = sprintf(<<<'PHP'
public function %s: void
    {
        $log = getenv('EVOLVE_MODULE_SDK_EVENT_LOG');
        if ($log !== false) {
            file_put_contents($log, '%s' . PHP_EOL, FILE_APPEND);
        }
    }
PHP, $signature, $event);
        $source = str_replace($old, $new, $source, $count);
        if ($count !== 1) {
            throw new RuntimeException('Generated Billing module lifecycle shape changed: ' . $signature);
        }
    }
    if (file_put_contents($path, $source) === false) {
        throw new RuntimeException('Cannot instrument generated Billing module.');
    }
}

function verifyConsumer(string $application): void
{
    demand(is_dir($application) && is_file($application . '/vendor/autoload.php'), 'Generated application is missing.');
    instrumentGeneratedModule($application);
    require $application . '/vendor/autoload.php';

    $installed = realpath($application . '/vendor/acme/module-sdk-billing');
    $appRoot = realpath($application);
    demand($installed !== false && $appRoot !== false, 'Installed library path is missing.');
    demand(str_starts_with($installed, $appRoot . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR), 'Library was not installed under vendor.');
    demand(!is_link($application . '/vendor/acme/module-sdk-billing'), 'Installed library is a symlink.');
    $class = new ReflectionClass(Acme\ModuleSdk\BillingModule::class);
    demand(str_starts_with((string) realpath((string) $class->getFileName()), $installed . DIRECTORY_SEPARATOR), 'Module class did not load from installed vendor package.');
    demand(!str_starts_with($class->getName(), 'App\\'), 'Installed module uses application namespace.');

    $appDefinition = require $application . '/src/Modules/Billing/module.php';
    $libraryDefinition = require $installed . '/module.php';
    demand($appDefinition instanceof ModuleDefinition && $libraryDefinition instanceof ModuleDefinition, 'Module descriptor loading failed.');
    demand($appDefinition->identifier()->value() === 'app/billing' && $libraryDefinition->identifier()->value() === 'acme/billing', 'Module descriptors have wrong identifiers.');

    $eventLog = $application . '/module-sdk-events.log';
    if (file_put_contents($eventLog, '') === false) {
        throw new RuntimeException('Cannot create module event log.');
    }
    putenv('EVOLVE_MODULE_SDK_EVENT_LOG=' . $eventLog);
    $module = Acme\ModuleSdk\BillingModule::class;
    $module::$events = [];
    $registry = new ServiceRegistry();
    $kernel = new ApplicationKernel(
        new ArrayConfiguration(['evolve' => ['components' => ['enabled' => ['acme/billing', 'app/billing']]]]),
        [],
        $registry,
        new ComponentBootstrapper([$libraryDefinition, $appDefinition]),
    );
    $kernel->boot();
    demand($module::$events === ['register', 'boot', 'ready'], 'Enabled installed module lifecycle did not run in dependency order.');
    demand(file($eventLog, FILE_IGNORE_NEW_LINES) === ['app:register', 'acme:register', 'app:boot', 'acme:boot', 'app:ready', 'acme:ready'], 'Generated and installed module registration or boot order is incorrect.');
    demand($registry->freeze()->get('acme.billing') === 'installed', 'Module service contribution is unavailable.');
    $kernel->shutdown();
    demand($module::$events === ['register', 'boot', 'ready', 'shutdown'], 'Successful module shutdown did not run.');
    demand(file($eventLog, FILE_IGNORE_NEW_LINES) === ['app:register', 'acme:register', 'app:boot', 'acme:boot', 'app:ready', 'acme:ready', 'acme:shutdown', 'app:shutdown'], 'Generated and installed module shutdown order is incorrect.');
    file_put_contents($eventLog, '');

    $module::$events = [];
    $disabled = new ApplicationKernel(new ArrayConfiguration(), [], null, new ComponentBootstrapper([$libraryDefinition, $appDefinition]));
    $disabled->boot();
    $disabled->shutdown();
    demand($module::$events === [], 'Disabled installed module had lifecycle effects.');
    demand(file($eventLog, FILE_IGNORE_NEW_LINES) === [], 'Disabled modules had generated or installed lifecycle effects.');

    try {
        (new ApplicationKernel(
            new ArrayConfiguration(['evolve' => ['components' => ['enabled' => ['acme/missing']]]]),
            [],
            null,
            new ComponentBootstrapper([$libraryDefinition, $appDefinition]),
        ))->boot();
        throw new RuntimeException('Unknown installed-module identifier was not rejected.');
    } catch (Evolve\Core\Exception\InvalidConfiguration $expected) {
        demand($module::$events === [] && file($eventLog, FILE_IGNORE_NEW_LINES) === [], 'Unknown enablement ran a module lifecycle.');
    }

    $module::$events = [];
    try {
        (new ApplicationKernel(
            new ArrayConfiguration(['evolve' => ['components' => ['enabled' => ['acme/billing']]]]),
            [],
            null,
            new ComponentBootstrapper([$libraryDefinition, $appDefinition]),
        ))->boot();
        throw new RuntimeException('Required dependency was not rejected.');
    } catch (Evolve\Core\Exception\MissingComponentDependency $expected) {
        demand($expected->consumer()->value() === 'acme/billing' && $expected->dependency()->value() === 'app/billing', 'Required dependency failure identified wrong modules.');
        demand($module::$events === [] && file($eventLog, FILE_IGNORE_NEW_LINES) === [], 'Graph rejection ran module lifecycle.');
    }

    $module::$events = [];
    $module::$failBoot = true;
    try {
        (new ApplicationKernel(
            new ArrayConfiguration(['evolve' => ['components' => ['enabled' => ['app/billing', 'acme/billing']]]]),
            [],
            null,
            new ComponentBootstrapper([$appDefinition, $libraryDefinition]),
        ))->boot();
        throw new RuntimeException('Intentional boot failure was not propagated.');
    } catch (Throwable $expected) {
        demand(str_contains((string) $expected, 'Intentional module boot failure.'), 'Unexpected boot failure.');
        demand($module::$events === ['register', 'boot', 'cleanup'], 'Failure cleanup or failure isolation is incorrect.');
    } finally {
        $module::$failBoot = false;
    }

    $policy = new LocalizationPolicy(['en', 'fr'], 'en', [], 'UTC');
    $catalog = new FilesystemMessageCatalog([new MessageSource('acme-billing', $installed . '/lang')], $policy);
    $factory = new ExecutionLocalizationContextFactory($policy);
    $english = $factory->fromExecution(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::CliCommand, new ExecutionContextValues('en')));
    $french = $factory->fromExecution(new ExecutionContext(ExecutionIdentifier::generate(), ExecutionKind::CliCommand, new ExecutionContextValues('fr')));
    demand($catalog->get(new MessageName('acme-billing::greeting'), $english->locale()) === 'Hello', 'English module catalog failed.');
    demand($catalog->get(new MessageName('acme-billing::greeting'), $french->locale()) === 'Bonjour', 'French module catalog failed.');
    demand($english->locale() === 'en', 'Execution locale leaked between contexts.');
    demand($catalog->get(new MessageName('other::greeting'), 'en') === null, 'Module catalog leaked into another namespace.');

    $definitions = require $installed . '/migrations.php';
    $registry = new MigrationRegistry($definitions);
    demand(count($registry->definitions()) === 1 && $registry->definitions()[0]->owner()->key() === 'module:acme/billing', 'Module migration ownership failed.');
    $history = new class implements MigrationHistoryStore {
        /** @var list<AppliedMigration> */
        public array $records = [];
        public function all(): iterable { return $this->records; }
        public function record(AppliedMigration $migration): void { $this->records[] = $migration; }
    };
    $module::$migrationRuns = 0;
    $pending = (new MigrationPlanner($registry, $history))->plan();
    demand($pending->entries()[0]->status() === MigrationPlanStatus::Pending && $module::$migrationRuns === 0, 'Planning or boot ran migration automatically.');
    $definition = $registry->definitions()[0];
    $definition->action()->invoke(null);
    demand($module::$migrationRuns === 1, 'Explicit application-owned migration execution failed.');
    $history->record(new AppliedMigration(MigrationOwner::module(new ComponentIdentifier('acme/billing')), new MigrationIdentifier('create-ledger'), 10, str_repeat('b', 64)));
    demand((new MigrationPlanner($registry, $history))->plan()->entries()[0]->status() === MigrationPlanStatus::Drifted, 'Migration checksum drift was not detected.');
}

try {
    $mode = $argv[1] ?? '';
    $path = $argv[2] ?? '';
    if ($path === '') {
        throw new RuntimeException('Generated application or package path is required.');
    }
    if ($mode === 'instrument') {
        instrumentGeneratedModule($path);
    } elseif ($mode === 'prepare') {
        preparePackage($path);
    } elseif ($mode === 'verify') {
        verifyConsumer($path);
    } else {
        throw new RuntimeException('Unknown fixture mode.');
    }
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Module SDK consumer failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
