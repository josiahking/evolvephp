<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2PublicDocumentationSiteTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testMkDocsSourceAndNextManualAreComplete(): void
    {
        foreach ([
            'website/mkdocs.yml',
            'website/build_docs.py',
            'website/requirements-docs.txt',
            'website/requirements-audit.txt',
            'website/docs/index.md',
            'website/docs/.nojekyll',
            'website/docs/next/index.md',
            'website/docs/next/packages/mcp.md',
            'website/docs/next/packages/redis.md',
            'website/docs/next/packages/audit-log.md',
            'website/docs/next/packages/webhooks.md',
            'website/docs/next/packages/api-keys.md',
            'website/docs/next/packages/symfony-mailer.md',
            'website/docs/next/packages/monolog.md',
            'website/docs/next/packages/symfony-validator.md',
            'website/docs/next/packages/symfony-rate-limiter.md',
            'website/docs/next/project/documentation-versioning.md',
            'website/docs/versions/index.md',
        ] as $path) {
            self::assertFileExists($this->root . '/' . $path, $path);
        }

        self::assertCount(27, $this->nextPages());
        self::assertFileDoesNotExist($this->root . '/website/package.json');
        self::assertFileDoesNotExist($this->root . '/website/docusaurus.config.ts');
        self::assertFileDoesNotExist($this->root . '/website/docs/versions/2.0.0-alpha.1');
    }

    public function testConfigurationAndPublicContentAreBounded(): void
    {
        $config = $this->read('website/mkdocs.yml');
        self::assertStringContainsString('site_name: EvolvePHP', $config);
        self::assertStringContainsString('site_url: https://josiahking.github.io/evolvephp/', $config);
        self::assertStringContainsString('name: mkdocs', $config);
        self::assertStringContainsString('- search', $config);
        self::assertStringContainsString('next/index.md', $config);
        self::assertStringContainsString('edit/2.x/website/docs/', $config);

        $home = $this->read('website/docs/index.md');
        self::assertStringContainsString('EvolvePHP 2 — Developer manual (Next)', $home);
        self::assertStringContainsString(
            '[Read the manual](next/index.md) · [View the repository](https://github.com/josiahking/evolvephp)',
            $home,
        );

        self::assertStringNotContainsString(
            '[Read the manual](next/index.md) ? [View the repository](https://github.com/josiahking/evolvephp)',
            $home,
        );
        $status = $this->read('website/docs/next/getting-started/current-status.md');
        self::assertMatchesRegularExpression('/pre-release/i', $status);
        self::assertMatchesRegularExpression('/not production-ready/i', $status);
        $mcp = $this->read('website/docs/next/packages/mcp.md');
        self::assertStringContainsString('McpComponentContribution', $mcp);
        self::assertStringContainsString('McpContributionServerComposer', $mcp);
        $redis = $this->read('website/docs/next/packages/redis.md');
        self::assertStringContainsString('RedisAdapter', $redis);
        self::assertStringContainsString('Psr16Cache', $redis);
        self::assertStringContainsString('tools/validate-redis-compatibility.php', $redis);
        self::assertStringContainsString('use Psr\SimpleCache\CacheInterface;', $redis);
        self::assertStringContainsString('function __construct(CacheInterface $cache)', $redis);
        self::assertStringContainsString("get('example', 'fallback')", $redis);
        self::assertStringContainsString("set('example', 'value', 3600)", $redis);
        self::assertStringContainsString("delete('example')", $redis);
        self::assertStringNotContainsString("require __DIR__ . '/vendor/autoload.php';", $redis);
        self::assertStringContainsString('## Use the cache', $redis);
        self::assertStringContainsString('## Install optional packages', $redis);
        self::assertLessThan(strpos($redis, '## Install optional packages'), strpos($redis, '## Use the cache'));
        self::assertStringContainsString('next/packages/redis.md', $config);
        self::assertStringContainsString('(redis.md)', $this->read('website/docs/next/packages/overview.md'));
        self::assertStringContainsString('next/packages/audit-log.md', $config);
        self::assertStringContainsString('(audit-log.md)', $this->read('website/docs/next/packages/overview.md'));
        self::assertStringContainsString('next/packages/webhooks.md', $config);
        self::assertStringContainsString('next/packages/api-keys.md', $config);
        self::assertStringContainsString('(webhooks.md)', $this->read('website/docs/next/packages/overview.md'));
        self::assertStringContainsString('(api-keys.md)', $this->read('website/docs/next/packages/overview.md'));
        self::assertStringContainsString('next/packages/symfony-mailer.md', $config);
        self::assertStringContainsString('(symfony-mailer.md)', $this->read('website/docs/next/packages/overview.md'));
        self::assertSame(1, substr_count($config, 'next/packages/monolog.md'));
        self::assertStringContainsString('(monolog.md)', $this->read('website/docs/next/packages/overview.md'));
        self::assertSame(1, substr_count($config, 'next/packages/symfony-validator.md'));
        self::assertStringContainsString('(symfony-validator.md)', $this->read('website/docs/next/packages/overview.md'));
        self::assertStringNotContainsString('<<<<<<<', $config);

        foreach ($this->nextPages() as $path) {
            $content = file_get_contents($path);
            self::assertIsString($content);
            self::assertDoesNotMatchRegularExpression('/\b(?:CURRENT_GATE|PREVIOUS_ACCEPTED_GATE|Notion|ChatGPT|Codex|assistant review|Docusaurus)\b/i', $content, $path);
        }
    }

    public function testBuildAndDependencyControlsAreExplicit(): void
    {
        $build = $this->read('website/build_docs.py');
        self::assertStringContainsString('"--strict"', $build);
        self::assertStringContainsString('".nojekyll"', $build);
        self::assertStringContainsString('sys.executable', $build);
        $ignore = $this->read('website/.gitignore');
        foreach (['build/', '.venv/', '.audit-venv/', '__pycache__/'] as $path) {
            self::assertStringContainsString($path, $ignore);
        }
        foreach (['website/requirements-docs.txt', 'website/requirements-audit.txt'] as $path) {
            foreach (array_filter(explode("\n", trim($this->read($path)))) as $line) {
                self::assertMatchesRegularExpression('/^[A-Za-z0-9._-]+==[A-Za-z0-9._+-]+$/', trim($line), $path);
            }
        }
        self::assertStringContainsString('mkdocs==1.6.1', $this->read('website/requirements-docs.txt'));
        self::assertStringContainsString('pip_audit==2.10.0', $this->read('website/requirements-audit.txt'));
        foreach (['website/requirements-docs.txt', 'website/requirements-audit.txt'] as $path) {
            $pins = preg_grep('/^setuptools==/i', preg_split('/\R/', trim($this->read($path))));
            self::assertSame(['setuptools==84.1.0'], array_values($pins), 'SETUPTOOLS_PIN_GUARD:' . $path);
        }
    }

    public function testDocumentationWorkflowBuildsWithoutPublishing(): void
    {
        $workflow = $this->read('.github/workflows/documentation.yml');
        self::assertStringContainsString('pull_request:', $workflow);
        self::assertStringContainsString('workflow_dispatch:', $workflow);
        self::assertStringContainsString("python: ['3.11', '3.13']", $workflow);
        self::assertStringContainsString("php-version: '8.4'", $workflow);
        self::assertStringContainsString('build_docs.py', $workflow);
        self::assertStringContainsString('pip_audit --path', $workflow);
        self::assertStringContainsString('tests/Documentation', $workflow);
        self::assertDoesNotMatchRegularExpression('/deploy-pages|gh-deploy|pages: write|id-token: write/', $workflow);
        self::assertMatchesRegularExpression('/uses: actions\/setup-python@[a-f0-9]{40}\b/', $workflow);
    }

    public function testPhpExampleImportsResolveAgainstCurrentSource(): void
    {
        $checked = 0;
        $optionalMailerImports = [
            'Symfony\Component\Mailer\Mailer',
            'Symfony\Component\Mailer\MailerInterface',
            'Symfony\Component\Mailer\Transport',
            'Symfony\Component\Mailer\Transport\Smtp\SmtpTransport',
            'Symfony\Component\Mailer\Transport\TransportInterface',
            'Symfony\Component\Mailer\Exception\TransportExceptionInterface',
            'Symfony\Component\Mime\Address',
            'Symfony\Component\Mime\Email',
        ];
        $seenOptionalMailerImports = [];
        $optionalMonologImports = [
            'Monolog\Handler\TestHandler',
            'Monolog\Logger',
            'Monolog\LogRecord',
            'Psr\Log\LoggerInterface',
        ];
        $seenOptionalMonologImports = [];
        $optionalValidatorImports = [
            'Symfony\Component\Validator\Validation',
            'Symfony\Component\Validator\Validator\ValidatorInterface',
        ];
        $seenOptionalValidatorImports = [];
        $optionalRateLimiterImports = [
            'Symfony\Component\RateLimiter\RateLimiterFactory',
            'Symfony\Component\RateLimiter\Storage\InMemoryStorage',
            'Symfony\Component\RateLimiter\Storage\StorageInterface',
        ];
        $seenOptionalRateLimiterImports = [];
        foreach ($this->nextPages() as $path) {
            $content = file_get_contents($path);
            self::assertIsString($content);
            preg_match_all('/~~~php\s*\R(.*?)\R~~~/s', $content, $blocks);
            foreach ($blocks[1] as $block) {
                foreach (explode("\n", $block) as $line) {
                    $line = trim($line);
                    if (! str_starts_with($line, 'use ')) {
                        continue;
                    }
                    $symbol = rtrim(substr($line, 4), ';');
                    $checked++;
                    if (str_ends_with(str_replace(DIRECTORY_SEPARATOR, '/', $path), '/website/docs/next/packages/symfony-mailer.md')
                        && in_array($symbol, $optionalMailerImports, true)) {
                        $seenOptionalMailerImports[$symbol] = true;
                        continue;
                    }
                    if (str_ends_with(str_replace(DIRECTORY_SEPARATOR, '/', $path), '/website/docs/next/packages/monolog.md')
                        && in_array($symbol, $optionalMonologImports, true)) {
                        $seenOptionalMonologImports[$symbol] = true;
                        continue;
                    }
                    if (str_ends_with(str_replace(DIRECTORY_SEPARATOR, '/', $path), '/website/docs/next/packages/symfony-validator.md')
                        && in_array($symbol, $optionalValidatorImports, true)) {
                        $seenOptionalValidatorImports[$symbol] = true;
                        continue;
                    }
                    if (str_ends_with(str_replace(DIRECTORY_SEPARATOR, '/', $path), '/website/docs/next/packages/symfony-rate-limiter.md')
                        && in_array($symbol, $optionalRateLimiterImports, true)) {
                        $seenOptionalRateLimiterImports[$symbol] = true;
                        continue;
                    }
                    self::assertTrue(
                        class_exists($symbol) || interface_exists($symbol)
                        || trait_exists($symbol) || enum_exists($symbol),
                        $path . ': missing example symbol ' . $symbol,
                    );
                }
            }
        }
        self::assertEqualsCanonicalizing($optionalMailerImports, array_keys($seenOptionalMailerImports));
        self::assertEqualsCanonicalizing($optionalMonologImports, array_keys($seenOptionalMonologImports));
        self::assertEqualsCanonicalizing($optionalValidatorImports, array_keys($seenOptionalValidatorImports));
        self::assertEqualsCanonicalizing($optionalRateLimiterImports, array_keys($seenOptionalRateLimiterImports));
        self::assertGreaterThanOrEqual(10, $checked);
    }

    public function testRedisOptionalSymbolsHaveIsolatedConsumerVerification(): void
    {
        $guide = $this->read('website/docs/next/packages/redis.md');
        self::assertStringContainsString('\\Symfony\\Component\\Cache\\Adapter\\RedisAdapter', $guide);
        self::assertStringContainsString('\\Symfony\\Component\\Cache\\Psr16Cache', $guide);
        self::assertStringContainsString('nonempty, application-owned namespace', $guide);
        self::assertStringContainsString('flush the entire selected Redis database', $guide);
        self::assertFileExists($this->root . '/tools/fixtures/redis-psr16-consumer.php');
        $validator = $this->read('tools/validate-redis-compatibility.php');
        self::assertStringContainsString('EVOLVE_REDIS_CONSUMER_AUTOLOAD', $validator);
        self::assertStringContainsString('RedisAdapter::class', $validator);
        self::assertStringContainsString('Psr16Cache::class', $validator);

        $autoload = getenv('EVOLVE_REDIS_CONSUMER_AUTOLOAD');
        if (is_string($autoload) && $autoload !== '') {
            self::assertFileExists($autoload);
            require_once $autoload;
            self::assertTrue(class_exists(\Symfony\Component\Cache\Adapter\RedisAdapter::class));
            self::assertTrue(class_exists(\Symfony\Component\Cache\Psr16Cache::class));
        }
    }

    public function testSymfonyMailerOptionalGuideHasIsolatedConsumerVerification(): void
    {
        $guide = $this->read('website/docs/next/packages/symfony-mailer.md');
        $overview = $this->read('website/docs/next/packages/overview.md');
        $config = $this->read('website/mkdocs.yml');

        self::assertSame(1, substr_count($config, 'next/packages/symfony-mailer.md'));
        self::assertStringContainsString('[Symfony Mailer compatibility](symfony-mailer.md)', $overview);
        self::assertStringContainsString('optional upstream integration', $overview);
        self::assertStringNotContainsString('| [symfony-mailer]', $overview);
        foreach (['symfony/mailer:^7.4', 'ServiceLifetime::Execution', 'TwigViewRenderer', 'null://null', 'Mail delivery failed.'] as $required) {
            self::assertStringContainsString($required, $guide);
        }
        self::assertFileExists($this->root . '/tools/fixtures/symfony-mailer-consumer.php');
        self::assertFileExists($this->root . '/tools/validate-symfony-mailer-compatibility.php');
        $validator = $this->read('tools/validate-symfony-mailer-compatibility.php');
        self::assertStringContainsString('EVOLVE_MAILER_CONSUMER_AUTOLOAD', $validator);
        self::assertStringContainsString('sendNotice($firstMailer, $message)', $validator);
    }

    public function testSymfonyValidatorOptionalGuideHasIsolatedConsumerVerification(): void
    {
        $guide = $this->read('website/docs/next/packages/symfony-validator.md');
        $overview = $this->read('website/docs/next/packages/overview.md');
        $config = $this->read('website/mkdocs.yml');
        self::assertSame(1, substr_count($config, 'next/packages/symfony-validator.md'));
        self::assertStringContainsString('[Symfony Validator compatibility](symfony-validator.md)', $overview);
        self::assertStringContainsString('optional upstream integrations', $overview);
        self::assertStringNotContainsString('| [symfony-validator]', $overview);
        foreach (['symfony/validator:^7.4.23', 'ServiceLifetime::Execution', '#[Valid]',
            'create', 'update', 'AllowedRegion', 'HTTP 422', 'EVOLVE_VALIDATOR_CONSUMER_AUTOLOAD'] as $required) {
            self::assertStringContainsString($required, $guide);
        }
        self::assertFileExists($this->root . '/tools/fixtures/symfony-validator-consumer.php');
        $validator = $this->read('tools/validate-symfony-validator-compatibility.php');
        foreach (['EVOLVE_VALIDATOR_CONSUMER_AUTOLOAD', 'ExecutionScopeUnavailable',
            'ConstraintViolationListInterface', 'sensitive', 'finally', 'tags[1]', "['create']", "['update']"] as $required) {
            self::assertStringContainsString($required, $validator);
        }
        $autoload = getenv('EVOLVE_VALIDATOR_CONSUMER_AUTOLOAD');
        if (is_string($autoload) && $autoload !== '') {
            self::assertFileExists($autoload);
            require_once $autoload;
            self::assertTrue(interface_exists(\Symfony\Component\Validator\Validator\ValidatorInterface::class));
            self::assertTrue(class_exists(\Symfony\Component\Validator\Validation::class));
        }
    }

    public function testMonologOptionalGuideHasIsolatedConsumerVerification(): void
    {
        $guide = $this->read('website/docs/next/packages/monolog.md');
        $config = $this->read('website/mkdocs.yml');
        self::assertSame(1, substr_count($config, 'next/packages/monolog.md'));
        foreach (['monolog/monolog:^3.0', 'psr/log:^3.0', 'ServiceLifetime::Execution',
            'LogCorrelationProvider', 'structuredFields()', 'registerResetParticipant',
            'EVOLVE_MONOLOG_CONSUMER_AUTOLOAD', 'tools/validate-monolog-compatibility.php'] as $required) {
            self::assertStringContainsString($required, $guide);
        }
        self::assertFileExists($this->root . '/tools/fixtures/monolog-psr3-consumer.php');
        $validator = $this->read('tools/validate-monolog-compatibility.php');
        self::assertStringContainsString('EVOLVE_MONOLOG_CONSUMER_AUTOLOAD', $validator);
        self::assertStringContainsString('--base', $validator);
        self::assertStringContainsString('--correlation', $validator);
        $autoload = getenv('EVOLVE_MONOLOG_CONSUMER_AUTOLOAD');
        if (is_string($autoload) && $autoload !== '') {
            self::assertFileExists($autoload);
            require_once $autoload;
            self::assertTrue(class_exists(\Monolog\Logger::class));
            self::assertTrue(interface_exists(\Psr\Log\LoggerInterface::class));
        }
    }

    public function testSymfonyRateLimiterOptionalGuideHasIsolatedConsumerVerification(): void
    {
        $guide = $this->read('website/docs/next/packages/symfony-rate-limiter.md');
        $config = $this->read('website/mkdocs.yml');
        $overview = $this->read('website/docs/next/packages/overview.md');
        self::assertSame(1, substr_count($config, 'next/packages/symfony-rate-limiter.md'));
        self::assertStringContainsString('[Symfony Rate Limiter compatibility](symfony-rate-limiter.md)', $overview);
        foreach (['symfony/rate-limiter:^7.4', 'ServiceLifetime::Application', 'MiddlewarePipeline',
            'HttpKernel', 'HTTP 429', 'Retry-After', 'EVOLVE_RATE_LIMITER_CONSUMER_AUTOLOAD', 'sequential'] as $required) {
            self::assertStringContainsString($required, $guide);
        }
        $fixture = $this->read('tools/fixtures/symfony-rate-limiter-consumer.php');
        foreach (['consume(1)', 'trusted_tenant_id', 'trusted_principal_id', 'createResponse(429)', 'createResponse(503)'] as $required) {
            self::assertStringContainsString($required, $fixture);
        }
        $validator = $this->read('tools/validate-symfony-rate-limiter-compatibility.php');
        foreach (['EVOLVE_RATE_LIMITER_CONSUMER_AUTOLOAD', 'StorageInterface', 'ExecutionScopeClosed', 'Retry-After'] as $required) {
            self::assertStringContainsString($required, $validator);
        }
        $autoload = getenv('EVOLVE_RATE_LIMITER_CONSUMER_AUTOLOAD');
        if (is_string($autoload) && $autoload !== '') {
            self::assertFileExists($autoload);
            require_once $autoload;
            self::assertTrue(class_exists(\Symfony\Component\RateLimiter\RateLimiterFactory::class));
            self::assertTrue(class_exists(\Symfony\Component\RateLimiter\Storage\InMemoryStorage::class));
        }
    }

    /** @return list<string> */
    private function nextPages(): array
    {
        $pages = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $this->root . '/website/docs/next',
            FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'md') {
                $pages[] = $file->getPathname();
            }
        }
        return $pages;
    }

    private function read(string $path): string
    {
        $content = file_get_contents($this->root . '/' . $path);
        self::assertIsString($content, $path);
        return $content;
    }
}
