<?php

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
            'deptrac.baseline.yaml',
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

        foreach ($this->expectedFirstPartyLayers() as $layerName => $pathPattern) {
            $this->assertMatchesPattern('/Layer::withName\(\'' . preg_quote($layerName, '/') . '\'/', $content);
            $this->assertStringContainsString("DirectoryConfig::create('" . $pathPattern . "')", $content);
        }

        $this->assertSame($this->expectedFirstPartyLayers(), $this->deptracLayerDirectories($content));
        $this->assertSame(
            array(
                'PsrContainer' => '^Psr\\\\Container\\\\.*',
                'PsrHttpMessage' => '^Psr\\\\Http\\\\Message\\\\.*',
                'PsrHttpServer' => '^Psr\\\\Http\\\\Server\\\\.*',
            ),
            $this->deptracExternalClassLikeLayers($content)
        );
        $this->assertSame($this->expectedRulesets(), $this->deptracRulesets($content));

        foreach (array('packages/contracts/tests', 'packages/core/tests', 'packages/dev-tools/tests', 'packages/http/tests', 'packages/module/tests', 'packages/plugin/tests', 'packages/testing/tests') as $testPath) {
            $this->assertStringNotContainsString($testPath, $content);
        }

        foreach (array('baseline', 'skipViolations', 'skip_violations', 'imports', 'Graphviz', 'Mermaid', 'formatter', 'FeatureFlagsConfig', 'phpstanParser', 'ComposerConfig', 'CollectorInterface', 'services(') as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $content);
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

            $this->assertDoesNotMatchPattern('/deptrac.*baseline/', $lower);
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
            '/no baseline|baseline.*not/i',
            '/no graph|graph.*not/i',
            '/PHP 8\.5.*CI matrix|CI matrix.*PHP 8\.5/i',
        ) as $developmentPattern) {
            $this->assertMatchesPattern($developmentPattern, $developmentGuide);
        }

        foreach (array(
            'Contracts -> none',
            'Core      -> Contracts',
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
        $this->assertMatchesPattern('/runtime implementation.*not yet present|not yet present.*runtime implementation/i', $packagesReadme);
        $this->assertMatchesPattern('/PSR HTTP.*middleware|middleware.*PSR HTTP|PSR-15.*middleware/is', $packagesReadme);
        $this->assertMatchesPattern('/MiddlewarePipeline/i', $packagesReadme);
        $this->assertMatchesPattern('/route definitions.*matching|RouteCollection.*RouteMatcher/is', $packagesReadme);
        $this->assertMatchesPattern('/packages.*not yet published|not yet published.*packages/i', $packagesReadme);

        $this->assertMatchesPattern('/PsrContainer/i', $developmentGuide);
        $this->assertMatchesPattern('/Contracts external standards.*PsrContainer|PsrContainer.*Contracts external standards/is', $developmentGuide);
        $this->assertMatchesPattern('/ServiceDefinitionRegistrar.*service-definition factory contract|service-definition factory contract.*ServiceDefinitionRegistrar/is', $developmentGuide);
        $this->assertMatchesPattern('/PsrHttpMessage/i', $developmentGuide);
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
            'Core' => 'packages/core/src/.*',
            'DevTools' => 'packages/dev-tools/src/.*',
            'Http' => 'packages/http/src/.*',
            'Module' => 'packages/module/src/.*',
            'Plugin' => 'packages/plugin/src/.*',
            'Testing' => 'packages/testing/src/.*',
        );
    }

    private function expectedRulesets()
    {
        return array(
            'Contracts' => array('PsrContainer'),
            'PsrContainer' => array(),
            'PsrHttpMessage' => array(),
            'PsrHttpServer' => array(),
            'Core' => array('Contracts', 'PsrContainer'),
            'DevTools' => array('Contracts', 'Core', 'Module', 'Plugin'),
            'Http' => array('Contracts', 'Core', 'PsrHttpMessage', 'PsrHttpServer'),
            'Module' => array('Contracts'),
            'Plugin' => array('Contracts'),
            'Testing' => array('Contracts', 'Core', 'Http', 'Module', 'Plugin'),
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
            'psrContainer' => 'PsrContainer',
            'psrHttpMessage' => 'PsrHttpMessage',
            'psrHttpServer' => 'PsrHttpServer',
            'core' => 'Core',
            'devTools' => 'DevTools',
            'http' => 'Http',
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
                preg_match_all('/\\$(contracts|psrContainer|psrHttpMessage|psrHttpServer|core|devTools|http|module|plugin|testing)\\b/', $match[1], $accessMatches);

                foreach ($accessMatches[1] as $accessVariable) {
                    $accesses[] = $variablesByLayer[$accessVariable];
                }
            }

            $rulesets[$layerName] = $accesses;
        }

        foreach (array('Contracts', 'PsrContainer', 'PsrHttpMessage', 'PsrHttpServer', 'Core', 'DevTools', 'Http', 'Module', 'Plugin') as $productionLayer) {
            $this->assertNotContains('Testing', $rulesets[$productionLayer], $productionLayer . ' must not access Testing.');
        }

        foreach (array('DevTools', 'Http', 'Module', 'Plugin', 'Testing') as $layerName) {
            $this->assertNotContains('PsrContainer', $rulesets[$layerName], $layerName . ' must not access PsrContainer directly without an approved boundary.');
        }

        foreach (array('Contracts', 'Core', 'DevTools', 'Module', 'Plugin', 'Testing') as $layerName) {
            $this->assertNotContains('PsrHttpMessage', $rulesets[$layerName], $layerName . ' must not access PSR-7 HTTP message interfaces directly.');
            $this->assertNotContains('PsrHttpServer', $rulesets[$layerName], $layerName . ' must not access PSR-15 HTTP server interfaces directly.');
        }

        return $rulesets;
    }

    private function packageSourceRules()
    {
        return array(
            'packages/contracts/src' => 'Evolve\\Contracts\\',
            'packages/core/src' => 'Evolve\\Core\\',
            'packages/dev-tools/src' => 'Evolve\\DevTools\\',
            'packages/http/src' => 'Evolve\\Http\\',
            'packages/module/src' => 'Evolve\\Module\\',
            'packages/plugin/src' => 'Evolve\\Plugin\\',
            'packages/testing/src' => 'Evolve\\Testing\\',
        );
    }

    private function packageManifests()
    {
        return array(
            'packages/contracts/composer.json',
            'packages/core/composer.json',
            'packages/dev-tools/composer.json',
            'packages/http/composer.json',
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
