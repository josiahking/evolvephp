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
            'website/docs/next/project/documentation-versioning.md',
            'website/docs/versions/index.md',
        ] as $path) {
            self::assertFileExists($this->root . '/' . $path, $path);
        }

        self::assertCount(19, $this->nextPages());
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
            $home
        );

        self::assertStringNotContainsString(
            '[Read the manual](next/index.md) ? [View the repository](https://github.com/josiahking/evolvephp)',
            $home
        );
        $status = $this->read('website/docs/next/getting-started/current-status.md');
        self::assertMatchesRegularExpression('/pre-release/i', $status);
        self::assertMatchesRegularExpression('/not production-ready/i', $status);
        $mcp = $this->read('website/docs/next/packages/mcp.md');
        self::assertStringContainsString('McpComponentContribution', $mcp);
        self::assertStringContainsString('McpContributionServerComposer', $mcp);

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
                    self::assertTrue(
                        class_exists($symbol) || interface_exists($symbol)
                        || trait_exists($symbol) || enum_exists($symbol),
                        $path . ': missing example symbol ' . $symbol
                    );
                }
            }
        }
        self::assertGreaterThanOrEqual(10, $checked);
    }

    private function nextPages(): array
    {
        $pages = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $this->root . '/website/docs/next',
            FilesystemIterator::SKIP_DOTS
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
