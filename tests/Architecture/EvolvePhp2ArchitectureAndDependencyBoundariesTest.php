<?php

use Deptrac\Deptrac\Contract\Config\DeptracConfig;
use PHPUnit\Framework\TestCase;

final class EvolvePhp2ArchitectureAndDependencyBoundariesTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testRootOwnsDeptracAsTheOnlyArchitectureBoundaryDependency(): void
    {
        $rootManifest = $this->readJsonFile('composer.json');

        $this->assertArrayHasKey('require-dev', $rootManifest);
        $this->assertArrayHasKey('deptrac/deptrac', $rootManifest['require-dev']);
        $this->assertSame('^4.7', $rootManifest['require-dev']['deptrac/deptrac']);
        $this->assertArrayNotHasKey('deptrac/deptrac', $rootManifest['require']);

        foreach ($this->packageManifests() as $path) {
            $this->assertPackageAbsentFromManifest('deptrac/deptrac', $this->readJsonFile($path), $path);
        }

        foreach (array('qossmic/deptrac', 'phparkitect/phparkitect', 'shipmonk/composer-dependency-analyser') as $package) {
            $this->assertPackageAbsentFromManifest($package, $rootManifest, 'composer.json');

            foreach ($this->packageManifests() as $path) {
                $this->assertPackageAbsentFromManifest($package, $this->readJsonFile($path), $path);
            }
        }
    }

    public function testRootDeclaresExactNonMutatingArchitectureScriptAndQualityPipeline(): void
    {
        $manifest = $this->readJsonFile('composer.json');
        $scripts = $manifest['scripts'];

        $expectedArchitecture = '@php vendor/bin/deptrac analyse --config-file=deptrac.php --no-progress --report-uncovered --fail-on-uncovered';
        $this->assertArrayHasKey('architecture', $scripts);
        $this->assertSame($expectedArchitecture, $scripts['architecture']);
        $this->assertStringStartsWith('@php ', $scripts['architecture']);
        $this->assertStringContainsString('vendor/bin/deptrac', $scripts['architecture']);
        $this->assertStringContainsString('deptrac.php', $scripts['architecture']);
        $this->assertStringContainsString('--no-progress', $scripts['architecture']);
        $this->assertStringContainsString('--report-uncovered', $scripts['architecture']);
        $this->assertStringContainsString('--fail-on-uncovered', $scripts['architecture']);

        foreach (array(' init', ' baseline', ' graph', ' debug', '--formatter=baseline', '--formatter=graphviz', '--formatter=mermaid') as $mutatingOrGeneratedCommand) {
            $this->assertStringNotContainsString($mutatingOrGeneratedCommand, $scripts['architecture']);
        }

        $expectedQuality = array('@architecture', '@analyse', '@style:check', '@test');
        $this->assertArrayHasKey('quality', $scripts);
        $this->assertSame($expectedQuality, $scripts['quality']);
        $this->assertSame('@architecture', $scripts['quality'][0], 'architecture must run first in quality.');
        $this->assertSame($scripts['quality'], array_values(array_unique($scripts['quality'])));
        $this->assertNotContains('@style:fix', $scripts['quality']);

        foreach ($scripts['quality'] as $entry) {
            foreach (array('baseline', 'graph', 'debug', 'security', 'ci') as $forbidden) {
                $this->assertStringNotContainsString($forbidden, strtolower($entry));
            }
        }
    }

    public function testDeptracConfigurationIsRootOwnedAndStrict(): void
    {
        $this->assertFileExists($this->projectPath('deptrac.php'));

        $trackedFiles = $this->trackedFiles();
        $forbiddenBasenameAlternatives = array(
            'deptrac.yaml',
            'deptrac.yml',
            'deptrac.yaml.dist',
            'deptrac.baseline.yml',
            'deptrac-baseline.php',
        );

        foreach ($trackedFiles as $file) {
            $normalized = str_replace('\\', '/', $file);

            $this->assertNotContains(basename($normalized), $forbiddenBasenameAlternatives, $normalized . ' must not be tracked.');
        }

        $gitignore = $this->readProjectFile('.gitignore');
        $this->assertStringContainsString('/.deptrac.cache', $gitignore);
        $this->assertNotContains('.deptrac.cache', $trackedFiles, 'Deptrac cache must not be tracked.');

        $content = $this->readProjectFile('deptrac.php');
        $baselinePath = 'deptrac.baseline.yaml';

        $this->assertFileExists($this->projectPath($baselinePath));
        $this->assertSame(
            "deptrac:\n"
            . "  skip_violations:\n"
            . "    Evolve\\Storage\\Local\\Internal\\LocalFilesystemStorageException:\n"
            . "      - Evolve\\Contracts\\Exception\\EvolveException\n",
            $this->readProjectFile($baselinePath),
        );
        $this->assertStringContainsString("->baseline(__DIR__ . '/deptrac.baseline.yaml')", $content);

        foreach ($this->expectedFirstPartyLayers() as $layerName => $pathPattern) {
            $this->assertMatchesPattern('/Layer::withName\(\'' . preg_quote($layerName, '/') . '\'/', $content);
            $this->assertStringContainsString("DirectoryConfig::create('" . $pathPattern . "')", $content);
        }

        $this->assertSame($this->expectedFirstPartyLayers(), $this->deptracLayerDirectories($content));
        $this->assertSame(
            array(
                'LaravelHost' => '^Illuminate\\\\(Contracts\\\\Auth|Http)\\\\.*',
                'SymfonyHost' => '^Symfony\\\\Component\\\\(HttpFoundation|Security\\\\Core)\\\\.*',
                'PsrContainer' => '^Psr\\\\Container\\\\.*',
                'PsrSimpleCache' => '^Psr\\\\SimpleCache\\\\.*',
                'PsrClock' => '^Psr\\\\Clock\\\\.*',
                'CronExpression' => '^Cron\\\\.*',
                'PsrHttpMessage' => '^Psr\\\\Http\\\\Message\\\\.*',
                'PsrHttpClient' => '^Psr\\\\Http\\\\Client\\\\.*',
                'PsrHttpServer' => '^Psr\\\\Http\\\\Server\\\\.*',
                'PhpIntl' => '^(IntlDateFormatter|MessageFormatter|NumberFormatter)$',
                'TwigEngine' => '^Twig\\\\(Environment|Source|Loader\\\\LoaderInterface|Error\\\\LoaderError)$',
                'IlluminateViewEngine' => '^Illuminate\\\\(Container\\\\Container|Contracts\\\\Support\\\\Arrayable|Events\\\\Dispatcher|Filesystem\\\\Filesystem|View\\\\.*)$',
                'McpSdk' => '^Mcp\\\\.*',
                'OpenTelemetryApi' => '^OpenTelemetry\\\\(API|Context)\\\\.*',
                'OpenTelemetrySdk' => '^OpenTelemetry\\\\SDK\\\\.*',
                'OpenTelemetrySemConv' => '^OpenTelemetry\\\\SemConv\\\\.*',
            ),
            $this->deptracExternalClassLikeLayers($content)
        );
        $this->assertSame($this->expectedRulesets(), $this->deptracRulesets($content));

        $config = new DeptracConfig();
        $configure = require $this->projectPath('deptrac.php');
        $configure($config);

        $this->assertSame(
            array(
                'Evolve\\Storage\\Local\\Internal\\LocalFilesystemStorageException' => array(
                    'Evolve\\Contracts\\Exception\\EvolveException',
                ),
            ),
            $config->toArray()['skip_violations'] ?? array(),
        );

        foreach (array('packages/contracts/tests', 'packages/database-contracts/tests', 'packages/database-pdo/tests', 'packages/session-contracts/tests', 'packages/lock-contracts/tests', 'packages/queue-contracts/tests', 'packages/queue-memory/tests', 'packages/storage-contracts/tests', 'packages/storage-local/tests', 'packages/secret-contracts/tests', 'packages/bridge-contracts/tests', 'packages/bridge-psr/tests', 'packages/bridge-laravel/tests', 'packages/bridge-symfony/tests', 'packages/bridge-remote/tests', 'packages/core/tests', 'packages/job/tests', 'packages/insight/tests', 'packages/observe/tests', 'packages/mcp/tests', 'packages/dev-tools/tests', 'packages/http/tests', 'packages/http-client/tests', 'packages/module/tests', 'packages/plugin/tests', 'packages/testing/tests') as $testPath) {
            $this->assertStringNotContainsString($testPath, $content);
        }

        foreach (array('imports', 'Graphviz', 'Mermaid', 'formatter', 'FeatureFlagsConfig', 'phpstanParser', 'ComposerConfig', 'CollectorInterface', 'services(') as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
        }

        foreach (array('ReflectionProperty', 'ReflectionClass', 'setValue(', 'Closure::bind', 'eval(', 'tempnam(', 'file_put_contents(') as $forbiddenConfigurationMechanism) {
            $this->assertStringNotContainsString($forbiddenConfigurationMechanism, $content);
        }
    }

    public function testProductionNamespacesMatchPhysicalPackageOwnership(): void
    {
        foreach ($this->packageSourceRules() as $directory => $namespacePrefix) {
            foreach ($this->trackedPhpFilesUnder($directory) as $file) {
                $content = $this->readProjectFile($file);

                $this->assertMatchesPattern(
                    '/^namespace\s+' . preg_quote(rtrim($namespacePrefix, '\\'), '/') . '(?:\\\\|;)/m',
                    $content
                );
            }
        }
    }

    public function testArchitectureFoundationDoesNotCommitGeneratedArchitectureArtifacts(): void
    {
        foreach ($this->trackedFiles() as $file) {
            $normalized = str_replace('\\', '/', $file);
            $lower = strtolower($normalized);

            if ('deptrac.baseline.yaml' !== $normalized) {
                $this->assertDoesNotMatchPattern('/deptrac.*baseline/', $lower);
            }
            $this->assertDoesNotMatchPattern('/deptrac.*graph/', $lower);
            $this->assertDoesNotMatchPattern('/Phase25.*BoundaryProbe/', $normalized);
        }
    }

    public function testDocumentationRecordsArchitectureBoundaryPolicy(): void
    {
        $developmentGuide = $this->readProjectFile('DEVELOPMENT.md');
        $packagesReadme = $this->readProjectFile('packages/README.md');
        $changelog = $this->readProjectFile('CHANGELOG.md');

        foreach (array(
            '/deptrac\/deptrac/i',
            '/qossmic\/deptrac/i',
            '/production source directories/i',
            '/tests? (?:are|is) excluded|excluded from Deptrac/i',
            '/physical package paths?/i',
            '/no production dependency on Testing/i',
            '/uncovered dependencies fail/i',
            '/exact .*deptrac\.baseline\.yaml/i',
            '/no graph|graph.*not/i',
            '/PHP 8\.5.*CI matrix|CI matrix.*PHP 8\.5/i',
        ) as $developmentPattern) {
            $this->assertMatchesPattern($developmentPattern, $developmentGuide);
        }

        foreach (array(
            'Contracts -> none',
            'DatabaseContracts -> Contracts',
            'DatabasePdo -> Contracts, DatabaseContracts',
            'StorageLocal -> StorageContracts',
            'SecretContracts -> Contracts',
            'SessionContracts -> Contracts',
            'LockContracts -> Contracts',
            'Core      -> Contracts',
            'Insight   -> Core, DatabaseContracts, Http, View, I18n, QueueContracts, StorageContracts, PsrSimpleCache, PsrHttpClient, PsrHttpMessage, PsrHttpServer',
            'BridgePsr -> BridgeContracts, Core, Http',
            'DevTools  -> Contracts, Core, Module, Plugin',
            'Http      -> Contracts, Core',
            'Module    -> Contracts',
            'Plugin    -> Contracts',
            'Testing   -> Contracts, Core, Http, Module, Plugin',
        ) as $matrixLine) {
            $this->assertStringContainsString($matrixLine, $developmentGuide);
        }

        $this->assertMatchesPattern('/depend inward|inward dependency/i', $packagesReadme);
        $this->assertMatchesPattern('/no production dependency on Testing/i', $packagesReadme);
        $this->assertMatchesPattern('/DEVELOPMENT\.md/i', $packagesReadme);
        $this->assertMatchesPattern('/runtime adapters.*remain deferred|remain deferred.*runtime adapters/i', $packagesReadme);
        $this->assertMatchesPattern('/PSR HTTP.*middleware|middleware.*PSR HTTP|PSR-15.*middleware/is', $packagesReadme);
        $this->assertMatchesPattern('/MiddlewarePipeline/i', $packagesReadme);
        $this->assertMatchesPattern('/route definitions.*matching|RouteCollection.*RouteMatcher/is', $packagesReadme);
        $this->assertMatchesPattern('/packages.*not yet published|not yet published.*packages/i', $packagesReadme);

        $this->assertMatchesPattern('/PsrContainer/i', $developmentGuide);
        $this->assertMatchesPattern('/Contracts external standards.*PsrContainer|PsrContainer.*Contracts external standards/is', $developmentGuide);
        $this->assertMatchesPattern('/ServiceDefinitionRegistrar.*service-definition factory contract|service-definition factory contract.*ServiceDefinitionRegistrar/is', $developmentGuide);
        $this->assertMatchesPattern('/PsrHttpMessage/i', $developmentGuide);
        $this->assertMatchesPattern('/PsrHttpClient/i', $developmentGuide);
        $this->assertMatchesPattern('/PsrHttpServer/i', $developmentGuide);
        $this->assertMatchesPattern('/PSR HTTP interfaces.*external interoperability standards|external interoperability standards.*PSR HTTP interfaces/is', $developmentGuide);

        $this->assertMatchesPattern('/Phase 2\.5/i', $changelog);
        $this->assertMatchesPattern('/Deptrac/i', $changelog);
        $this->assertMatchesPattern('/dependency-boundar/i', $changelog);
        $this->assertMatchesPattern('/Phase 4\.1/i', $changelog);
    }

    private function expectedFirstPartyLayers()
    {
        return array(
            'Contracts' => 'packages/contracts/src/.*',
            'DatabaseContracts' => 'packages/database-contracts/src/.*',
            'DatabasePdo' => 'packages/database-pdo/src/.*',
            'CacheMemory' => 'packages/cache-memory/src/.*',
            'SessionContracts' => 'packages/session-contracts/src/.*',
            'LockContracts' => 'packages/lock-contracts/src/.*',
            'QueueContracts' => 'packages/queue-contracts/src/.*',
            'QueueMemory' => 'packages/queue-memory/src/.*',
            'StorageContracts' => 'packages/storage-contracts/src/.*',
            'StorageLocal' => 'packages/storage-local/src/.*',
            'SecretContracts' => 'packages/secret-contracts/src/.*',
            'BridgeContracts' => 'packages/bridge-contracts/src/.*',
            'BridgePsr' => 'packages/bridge-psr/src/.*',
            'BridgeLaravel' => 'packages/bridge-laravel/src/.*',
            'BridgeSymfony' => 'packages/bridge-symfony/src/.*',
            'BridgeRemote' => 'packages/bridge-remote/src/.*',
            'Core' => 'packages/core/src/.*',
            'Job' => 'packages/job/src/.*',
            'Scheduler' => 'packages/scheduler/src/.*',
            'Migration' => 'packages/migration/src/.*',
            'View' => 'packages/view/src/.*',
            'ViewTwig' => 'packages/view-twig/src/.*',
            'ViewBlade' => 'packages/view-blade/src/.*',
            'I18n' => 'packages/i18n/src/.*',
            'Insight' => 'packages/insight/src/.*',
            'Observe' => 'packages/observe/src/.*',
            'Mcp' => 'packages/mcp/src/.*',
            'DevTools' => 'packages/dev-tools/src/.*',
            'Http' => 'packages/http/src/.*',
            'HttpClient' => 'packages/http-client/src/.*',
            'Module' => 'packages/module/src/.*',
            'Plugin' => 'packages/plugin/src/.*',
            'Testing' => 'packages/testing/src/.*',
        );
    }

    private function expectedRulesets()
    {
        return array(
            'Contracts' => array('PsrContainer'),
            'DatabaseContracts' => array('Contracts'),
            'DatabasePdo' => array('Contracts', 'DatabaseContracts'),
            'CacheMemory' => array('PsrSimpleCache', 'PsrClock'),
            'SessionContracts' => array('Contracts'),
            'LockContracts' => array('Contracts'),
            'QueueContracts' => array('Contracts'),
            'QueueMemory' => array('QueueContracts'),
            'StorageContracts' => array('Contracts'),
            'StorageLocal' => array('StorageContracts'),
            'SecretContracts' => array('Contracts'),
            'BridgeContracts' => array('Contracts'),
            'BridgePsr' => array('BridgeContracts', 'Core', 'Http', 'PsrHttpMessage'),
            'BridgeLaravel' => array('BridgeContracts', 'BridgePsr', 'PsrHttpMessage', 'LaravelHost'),
            'BridgeSymfony' => array('BridgeContracts', 'BridgePsr', 'PsrHttpMessage', 'SymfonyHost'),
            'BridgeRemote' => array('BridgeContracts', 'BridgePsr', 'PsrHttpMessage', 'PsrHttpClient', 'PsrHttpServer'),
            'LaravelHost' => array(),
            'SymfonyHost' => array(),
            'PsrContainer' => array(),
            'PsrSimpleCache' => array(),
            'PsrClock' => array(),
            'CronExpression' => array(),
            'PsrHttpMessage' => array(),
            'PsrHttpClient' => array(),
            'PsrHttpServer' => array(),
            'TwigEngine' => array(),
            'IlluminateViewEngine' => array(),
            'PhpIntl' => array(),
            'OpenTelemetryApi' => array(),
            'OpenTelemetrySdk' => array(),
            'OpenTelemetrySemConv' => array(),
            'McpSdk' => array(),
            'Core' => array('Contracts', 'PsrContainer'),
            'Job' => array('Core', 'QueueContracts'),
            'Scheduler' => array('Core', 'LockContracts', 'QueueContracts', 'PsrClock', 'CronExpression'),
            'Migration' => array('Contracts', 'Core', 'DatabaseContracts', 'LockContracts'),
            'View' => array(),
            'ViewTwig' => array('View', 'TwigEngine'),
            'ViewBlade' => array('View', 'IlluminateViewEngine'),
            'I18n' => array('Core', 'PhpIntl'),
            'Insight' => array('Core', 'DatabaseContracts', 'Http', 'View', 'I18n', 'QueueContracts', 'StorageContracts', 'PsrSimpleCache', 'PsrHttpClient', 'PsrHttpMessage', 'PsrHttpServer'),
            'Observe' => array('Core', 'DatabaseContracts', 'Http', 'HttpClient', 'Job', 'QueueContracts', 'StorageContracts', 'OpenTelemetryApi', 'OpenTelemetrySdk', 'OpenTelemetrySemConv', 'PsrHttpClient', 'PsrHttpMessage', 'PsrHttpServer', 'PsrSimpleCache'),
            'Mcp' => array('McpSdk'),
            'DevTools' => array('Contracts', 'Core', 'Module', 'Plugin'),
            'Http' => array('Contracts', 'Core', 'PsrHttpMessage', 'PsrHttpServer'),
            'HttpClient' => array('PsrHttpMessage', 'PsrHttpClient'),
            'Module' => array('Contracts'),
            'Plugin' => array('Contracts'),
            'Testing' => array('Contracts', 'Core', 'Http', 'Module', 'Plugin', 'View'),
        );
    }

    private function deptracLayerDirectories($content)
    {
        $layers = array();
        $pattern = "/\\$(\\w+)\\s*=\\s*Layer::withName\\('([^']+)'\\)->collectors\\(\\s*DirectoryConfig::create\\('([^']+)'\\),\\s*\\)/s";

        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $layers[$match[2]] = $match[3];
        }

        return $layers;
    }

    private function deptracExternalClassLikeLayers($content)
    {
        $layers = array();
        $pattern = "/\\$(\\w+)\\s*=\\s*Layer::withName\\('([^']+)'\\)->collectors\\(\\s*ClassLikeConfig::create\\('([^']+)'\\),\\s*\\)/s";

        preg_match_all($pattern, $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $layers[$match[2]] = $match[3];
        }

        return $layers;
    }

    private function deptracRulesets($content)
    {
        $variablesByLayer = array(
            'contracts' => 'Contracts',
            'databaseContracts' => 'DatabaseContracts',
            'databasePdo' => 'DatabasePdo',
            'cacheMemory' => 'CacheMemory',
            'sessionContracts' => 'SessionContracts',
            'lockContracts' => 'LockContracts',
            'queueContracts' => 'QueueContracts',
            'queueMemory' => 'QueueMemory',
            'storageContracts' => 'StorageContracts',
            'storageLocal' => 'StorageLocal',
            'secretContracts' => 'SecretContracts',
            'bridgeContracts' => 'BridgeContracts',
            'bridgePsr' => 'BridgePsr',
            'bridgeLaravel' => 'BridgeLaravel',
            'bridgeSymfony' => 'BridgeSymfony',
            'bridgeRemote' => 'BridgeRemote',
            'laravelHost' => 'LaravelHost',
            'symfonyHost' => 'SymfonyHost',
            'psrContainer' => 'PsrContainer',
            'psrSimpleCache' => 'PsrSimpleCache',
            'psrClock' => 'PsrClock',
            'cronExpression' => 'CronExpression',
            'psrHttpMessage' => 'PsrHttpMessage',
            'psrHttpClient' => 'PsrHttpClient',
            'psrHttpServer' => 'PsrHttpServer',
            'twigEngine' => 'TwigEngine',
            'illuminateViewEngine' => 'IlluminateViewEngine',
            'phpIntl' => 'PhpIntl',
            'openTelemetryApi' => 'OpenTelemetryApi',
            'openTelemetrySdk' => 'OpenTelemetrySdk',
            'openTelemetrySemConv' => 'OpenTelemetrySemConv',
            'mcpSdk' => 'McpSdk',
            'core' => 'Core',
            'job' => 'Job',
            'scheduler' => 'Scheduler',
            'migration' => 'Migration',
            'view' => 'View',
            'viewTwig' => 'ViewTwig',
            'viewBlade' => 'ViewBlade',
            'i18n' => 'I18n',
            'insight' => 'Insight',
            'observe' => 'Observe',
            'mcp' => 'Mcp',
            'devTools' => 'DevTools',
            'http' => 'Http',
            'httpClient' => 'HttpClient',
            'module' => 'Module',
            'plugin' => 'Plugin',
            'testing' => 'Testing',
        );
        $rulesets = array();

        foreach ($variablesByLayer as $variable => $layerName) {
            $pattern = '/Ruleset::forLayer\\(\\$' . $variable . '\\)(?:->accesses\\((.*?)\\))?/s';
            $this->assertSame(1, preg_match($pattern, $content, $match), 'Missing ruleset for ' . $layerName . '.');
            $accesses = array();

            if (isset($match[1])) {
                preg_match_all('/\\$(contracts|databaseContracts|databasePdo|cacheMemory|sessionContracts|lockContracts|queueContracts|storageContracts|storageLocal|bridgeContracts|bridgePsr|bridgeLaravel|bridgeSymfony|bridgeRemote|psrContainer|psrSimpleCache|psrClock|cronExpression|psrHttpMessage|psrHttpClient|psrHttpServer|twigEngine|illuminateViewEngine|phpIntl|openTelemetryApi|openTelemetrySdk|openTelemetrySemConv|mcpSdk|laravelHost|symfonyHost|core|job|scheduler|viewTwig|viewBlade|view|i18n|insight|observe|devTools|http|httpClient|module|plugin|testing)\\b/', $match[1], $accessMatches);

                foreach ($accessMatches[1] as $accessVariable) {
                    $accesses[] = $variablesByLayer[$accessVariable];
                }
            }

            $rulesets[$layerName] = $accesses;
        }

        foreach (array('Contracts', 'DatabaseContracts', 'DatabasePdo', 'CacheMemory', 'SessionContracts', 'LockContracts', 'StorageContracts', 'StorageLocal', 'SecretContracts', 'BridgeContracts', 'BridgePsr', 'BridgeLaravel', 'BridgeSymfony', 'BridgeRemote', 'PsrContainer', 'PsrSimpleCache', 'PsrClock', 'PsrHttpMessage', 'PsrHttpClient', 'PsrHttpServer', 'OpenTelemetryApi', 'OpenTelemetrySdk', 'OpenTelemetrySemConv', 'LaravelHost', 'SymfonyHost', 'Core', 'Insight', 'Observe', 'Mcp', 'DevTools', 'Http', 'Module', 'Plugin') as $productionLayer) {
            $this->assertNotContains('Testing', $rulesets[$productionLayer], $productionLayer . ' must not access Testing.');
        }

        foreach (array('DatabaseContracts', 'DatabasePdo', 'CacheMemory', 'SessionContracts', 'LockContracts', 'StorageContracts', 'StorageLocal', 'SecretContracts', 'BridgeContracts', 'BridgePsr', 'BridgeLaravel', 'BridgeSymfony', 'BridgeRemote', 'Insight', 'Observe', 'Mcp', 'DevTools', 'Http', 'Module', 'Plugin', 'Testing') as $layerName) {
            $this->assertNotContains('PsrContainer', $rulesets[$layerName], $layerName . ' must not access PsrContainer directly without an approved boundary.');
        }

        foreach (array('Contracts', 'DatabaseContracts', 'DatabasePdo', 'CacheMemory', 'SessionContracts', 'BridgeContracts', 'Core', 'DevTools', 'Module', 'Plugin', 'Testing') as $layerName) {
            $this->assertNotContains('PsrHttpMessage', $rulesets[$layerName], $layerName . ' must not access PSR-7 HTTP message interfaces directly.');
            $this->assertNotContains('PsrHttpClient', $rulesets[$layerName], $layerName . ' must not access PSR-18 HTTP client interfaces directly.');
            $this->assertNotContains('PsrHttpServer', $rulesets[$layerName], $layerName . ' must not access PSR-15 HTTP server interfaces directly.');
        }
        $this->assertContains('PsrHttpServer', $rulesets['Insight'], 'Insight must access PSR-15 HTTP server interfaces for explicit diagnostics.');

        return $rulesets;
    }

    private function packageSourceRules()
    {
        return array(
            'packages/contracts/src' => 'Evolve\\Contracts\\',
            'packages/database-contracts/src' => 'Evolve\\Database\\Contracts\\',
            'packages/database-pdo/src' => 'Evolve\\Database\\Pdo\\',
            'packages/cache-memory/src' => 'Evolve\\Cache\\Memory\\',
            'packages/session-contracts/src' => 'Evolve\\Session\\Contracts\\',
            'packages/lock-contracts/src' => 'Evolve\\Lock\\Contracts\\',
            'packages/queue-contracts/src' => 'Evolve\\Queue\\Contracts\\',
            'packages/queue-memory/src' => 'Evolve\\Queue\\Memory\\',
            'packages/storage-contracts/src' => 'Evolve\\Storage\\Contracts\\',
            'packages/storage-local/src' => 'Evolve\\Storage\\Local\\',
            'packages/secret-contracts/src' => 'Evolve\\Secret\\Contracts\\',
            'packages/bridge-contracts/src' => 'Evolve\\Bridge\\Contracts\\',
            'packages/bridge-psr/src' => 'Evolve\\Bridge\\Psr\\',
            'packages/bridge-laravel/src' => 'Evolve\\Bridge\\Laravel\\',
            'packages/bridge-symfony/src' => 'Evolve\\Bridge\\Symfony\\',
            'packages/bridge-remote/src' => 'Evolve\\Bridge\\Remote\\',
            'packages/core/src' => 'Evolve\\Core\\',
            'packages/job/src' => 'Evolve\\Job\\',
            'packages/scheduler/src' => 'Evolve\\Scheduler\\',
            'packages/migration/src' => 'Evolve\\Migration\\',
            'packages/view/src' => 'Evolve\\View\\',
            'packages/view-twig/src' => 'Evolve\\View\\Twig\\',
            'packages/view-blade/src' => 'Evolve\\View\\Blade\\',
            'packages/i18n/src' => 'Evolve\\I18n\\',
            'packages/insight/src' => 'Evolve\\Insight\\',
            'packages/observe/src' => 'Evolve\\Observe\\',
            'packages/mcp/src' => 'Evolve\\Mcp\\',
            'packages/dev-tools/src' => 'Evolve\\DevTools\\',
            'packages/http/src' => 'Evolve\\Http\\',
            'packages/http-client/src' => 'Evolve\\Http\\Client\\',
            'packages/module/src' => 'Evolve\\Module\\',
            'packages/plugin/src' => 'Evolve\\Plugin\\',
            'packages/testing/src' => 'Evolve\\Testing\\',
        );
    }

    private function packageManifests()
    {
        return array(
            'packages/contracts/composer.json',
            'packages/database-contracts/composer.json',
            'packages/database-pdo/composer.json',
            'packages/session-contracts/composer.json',
            'packages/lock-contracts/composer.json',
            'packages/queue-contracts/composer.json',
            'packages/queue-memory/composer.json',
            'packages/storage-contracts/composer.json',
            'packages/secret-contracts/composer.json',
            'packages/bridge-contracts/composer.json',
            'packages/bridge-psr/composer.json',
            'packages/bridge-laravel/composer.json',
            'packages/bridge-symfony/composer.json',
            'packages/bridge-remote/composer.json',
            'packages/core/composer.json',
            'packages/job/composer.json',
            'packages/scheduler/composer.json',
            'packages/migration/composer.json',
            'packages/view/composer.json',
            'packages/view-twig/composer.json',
            'packages/view-blade/composer.json',
            'packages/i18n/composer.json',
            'packages/insight/composer.json',
            'packages/dev-tools/composer.json',
            'packages/http/composer.json',
            'packages/http-client/composer.json',
            'packages/module/composer.json',
            'packages/plugin/composer.json',
            'packages/testing/composer.json',
        );
    }

    private function assertPackageAbsentFromManifest($package, array $manifest, $path): void
    {
        $this->assertArrayNotHasKey($package, isset($manifest['require']) ? $manifest['require'] : array(), $path . ' must not require ' . $package . '.');
        $this->assertArrayNotHasKey($package, isset($manifest['require-dev']) ? $manifest['require-dev'] : array(), $path . ' must not require-dev ' . $package . '.');
    }

    private function trackedPhpFilesUnder($directory)
    {
        $files = array();
        $prefix = rtrim(str_replace('\\', '/', $directory), '/') . '/';

        foreach ($this->trackedFiles() as $file) {
            $normalized = str_replace('\\', '/', $file);

            if (strpos($normalized, $prefix) === 0 && substr($normalized, -4) === '.php') {
                $files[] = $normalized;
            }
        }

        sort($files);

        return $files;
    }

    private function trackedFiles()
    {
        $output = array();
        $exitCode = 0;

        exec('git ls-files --cached --others --exclude-standard', $output, $exitCode);

        $this->assertSame(0, $exitCode, 'git ls-files should succeed.');

        return $output;
    }

    private function projectPath($path)
    {
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    }

    private function readProjectFile($path)
    {
        $fullPath = $this->projectPath($path);
        $this->assertFileExists($fullPath, $path . ' should exist before it is read.');

        return file_get_contents($fullPath);
    }

    private function readJsonFile($path)
    {
        $content = $this->readProjectFile($path);
        $json = json_decode($content, true);

        $this->assertSame(JSON_ERROR_NONE, json_last_error(), $path . ' should contain valid JSON: ' . json_last_error_msg());
        $this->assertIsArray($json, $path . ' should decode to a JSON object.');

        return $json;
    }

    private function assertMatchesPattern($pattern, $content): void
    {
        $this->assertSame(1, preg_match($pattern, $content), 'Failed asserting that content matches ' . $pattern);
    }

    private function assertDoesNotMatchPattern($pattern, $content): void
    {
        $this->assertSame(0, preg_match($pattern, $content), 'Failed asserting that content does not match ' . $pattern);
    }
}
