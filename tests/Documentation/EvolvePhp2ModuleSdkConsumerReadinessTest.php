<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EvolvePhp2ModuleSdkConsumerReadinessTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testSkeletonValidatorRunsIndependentModuleConsumer(): void
    {
        $fixture = $this->root . '/tools/fixtures/module-sdk-independent-consumer.php';
        self::assertFileExists($fixture);
        $validator = file_get_contents($this->root . '/tools/validate-skeleton-project.php');
        self::assertIsString($validator);
        self::assertStringContainsString('module-sdk-independent-consumer.php', $validator);
        self::assertStringContainsString('composer require', $validator);
    }

    public function testConsumerRejectsMissingGeneratedApplication(): void
    {
        $fixture = $this->root . '/tools/fixtures/module-sdk-independent-consumer.php';
        self::assertFileExists($fixture);
        $process = proc_open([PHP_BINARY, $fixture, 'verify', $this->root . '/missing-module-sdk-application'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(1, proc_close($process));
        self::assertSame('', $stdout);
        self::assertStringContainsString('application', (string) $stderr);
    }

    public function testPreparedPackageOwnsItsNamespaceAndExplicitContributions(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $temporary = createTemporaryDirectory('module-sdk-test-');
        $package = $temporary->child('library');
        try {
            $fixture = $this->root . '/tools/fixtures/module-sdk-independent-consumer.php';
            $process = proc_open([PHP_BINARY, $fixture, 'prepare', $package], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), (string) $stderr);
            self::assertSame('', $stdout);

            $manifest = json_decode((string) file_get_contents($package . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('acme/module-sdk-billing', $manifest['name']);
            self::assertSame('src/', $manifest['autoload']['psr-4']['Acme\\ModuleSdk\\']);
            self::assertSame('^2.0', $manifest['require']['evolvephp/module']);
            self::assertFileExists($package . '/module.php');
            self::assertFileExists($package . '/lang/en.php');
            self::assertFileExists($package . '/lang/fr.php');
            self::assertFileExists($package . '/migrations.php');
            self::assertStringNotContainsString('namespace App\\', (string) file_get_contents($package . '/src/BillingModule.php'));

            $again = proc_open([PHP_BINARY, $fixture, 'prepare', $package], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($again);
            fclose($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            self::assertSame(1, proc_close($again));
            self::assertStringContainsString('already exists', (string) $error);
        } finally {
            $temporary->cleanup();
        }
    }

    public function testGeneratedModuleCanRecordLifecycleEventsWithoutReplacingItsDescriptor(): void
    {
        require_once $this->root . '/tools/release-validation-common.php';
        $temporary = createTemporaryDirectory('module-sdk-instrument-test-');
        try {
            (new Evolve\DevTools\Console\ComponentScaffoldGenerator($temporary->path))->generateModule('Billing');
            $descriptor = $temporary->child('src/Modules/Billing/module.php');
            $before = file_get_contents($descriptor);
            $fixture = $this->root . '/tools/fixtures/module-sdk-independent-consumer.php';
            $process = proc_open([PHP_BINARY, $fixture, 'instrument', $temporary->path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), (string) $stderr);
            self::assertSame($before, file_get_contents($descriptor));
            $class = file_get_contents($temporary->child('src/Modules/Billing/BillingModule.php'));
            self::assertIsString($class);
            self::assertStringContainsString('namespace App\Modules\Billing;', $class);
            self::assertStringContainsString('app:register', $class);
            self::assertStringContainsString('app:boot', $class);
        } finally {
            $temporary->cleanup();
        }
    }

    public function testDocumentedModuleCompositionRunsWithGeneratedApplicationModule(): void
    {
        $readme = file_get_contents($this->root . '/skeleton/README.md');
        self::assertIsString($readme);
        $fence = str_repeat(chr(96), 3);
        self::assertSame(1, preg_match('/<!-- module-sdk-example -->\s*' . $fence . 'php\s*(.*?)\s*' . $fence . '/s', $readme, $matches));

        require_once $this->root . '/tools/release-validation-common.php';
        $temporary = createTemporaryDirectory('module-sdk-doc-example-');
        try {
            (new Evolve\DevTools\Console\ComponentScaffoldGenerator($temporary->path))->generateModule('Billing');
            $vendor = $temporary->child('vendor');
            self::assertTrue(mkdir($vendor));
            $bootstrap = '<?php $loader = require ' . var_export($this->root . '/vendor/autoload.php', true)
                . '; $loader->addPsr4(' . var_export('App\\', true) . ', '
                . var_export($temporary->child('src'), true) . '); return $loader;';
            self::assertNotFalse(file_put_contents($vendor . '/autoload.php', $bootstrap));
            $example = $temporary->child('example.php');
            self::assertNotFalse(file_put_contents($example, $matches[1]));
            $process = proc_open([PHP_BINARY, $example], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), (string) $stderr);
            self::assertSame('', $stdout);
            self::assertSame('', $stderr);
        } finally {
            $temporary->cleanup();
        }
    }

    public function testPublicDocumentationKeepsUnpublishedLimitationsExplicit(): void
    {
        foreach ([
            'packages/module/README.md',
            'skeleton/README.md',
            'website/docs/next/concepts/architecture.md',
            'website/docs/next/getting-started/source-preview.md',
        ] as $path) {
            $contents = file_get_contents($this->root . '/' . $path);
            self::assertIsString($contents);
            self::assertStringContainsString('module', strtolower($contents));
            self::assertMatchesRegularExpression('/not (yet )?(publicly |independently )?(published|installable)|public .* not .* available|publication .* remain unclaimed|availability is not yet claimed/i', $contents);
        }
    }
}
