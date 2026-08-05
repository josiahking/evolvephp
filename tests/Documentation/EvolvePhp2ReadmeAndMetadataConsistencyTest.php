<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2ReadmeAndMetadataConsistencyTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testTrackedReadmeInventoryIsTheExpectedCanonicalSet(): void
    {
        $this->assertSame(
            array(
                'README.md',
                'docs/rfcs/README.md',
                'packages/README.md',
                'workspace/README.md',
            ),
            $this->trackedReadmes()
        );
    }

    public function testRootReadmeIdentifiesEvolvePhp2BranchAndLegacyMasterLine(): void
    {
        $content = $this->readProjectFile('README.md');

        $this->assertMatchesPattern('/2\.x.*EvolvePHP 2|EvolvePHP 2.*2\.x/is', $content);
        $this->assertMatchesPattern('/master.*EvolvePHP 1|EvolvePHP 1.*master/is', $content);
        $this->assertMatchesPattern('/not an in-place refactor/i', $content);
        $this->assertMatchesPattern('/package.*workspace.*quality|quality.*workspace.*package/is', $content);
        $this->assertMatchesPattern('/runtime framework implementation.*not yet complete|not yet complete.*runtime framework implementation/is', $content);
        $this->assertMatchesPattern('/packages.*not yet published|not yet published.*packages/is', $content);
    }

    public function testRootReadmeDocumentsPhp84BaselineAndPhp85CiEvidence(): void
    {
        $content = $this->readProjectFile('README.md');

        $this->assertMatchesPattern('/requires PHP 8\.4|PHP 8\.4.*required/i', $content);
        $this->assertMatchesPattern('/PHP baseline:\s*PHP 8\.4|PHP 8\.4.*baseline/i', $content);
        $this->assertMatchesPattern('/GitHub Actions.*(?:PHP 8\.4.*PHP 8\.5|PHP 8\.5.*PHP 8\.4)|(?:PHP 8\.4.*PHP 8\.5|PHP 8\.5.*PHP 8\.4).*GitHub Actions/i', $content);
        $this->assertMatchesPattern('/workspace quality pipeline|quality pipeline.*workspace/i', $content);
        $this->assertMatchesPattern('/current.*(?:workspace|tooling|package foundation)|(?:workspace|tooling|package foundation).*current/i', $content);
        $this->assertMatchesPattern('/runtime framework implementation.*not yet complete|not yet complete.*runtime framework implementation/i', $content);
        $this->assertDoesNotMatchPattern('/PHP 8\.5.*pending|pending.*PHP 8\.5|Do not claim PHP 8\.5/i', $content);
        $this->assertDoesNotMatchPattern('/production[- ]ready|runtime framework supports PHP 8\.5|all runtime.*PHP 8\.5/i', $content);
    }

    public function testRootReadmeUsesWorkspaceSetupAndAvoidsLegacySetupAsEvolvePhp2Setup(): void
    {
        $content = $this->readProjectFile('README.md');

        $this->assertStringContainsString('git switch 2.x', $content);
        $this->assertStringContainsString('composer --working-dir=workspace install', $content);
        $this->assertStringContainsString('composer --working-dir=workspace quality', $content);
        $this->assertStringNotContainsString('php -S localhost:8800', $content);
        $this->assertDoesNotMatchPattern('/Review and update.*configs\//is', $content);
        $this->assertDoesNotMatchPattern('/route\.php.*dispatch|dispatch.*route\.php/is', $content);
    }

    public function testRootComposerMetadataUsesApprovedProfessionalValues(): void
    {
        $manifest = $this->readJsonFile('composer.json');
        $misspelledKeyword = 'elvovephp' . ' framework';

        $this->assertSame(
            'A lightweight component-based PHP framework for building structured web applications.',
            $manifest['description']
        );
        $this->assertContains('evolvephp framework', $manifest['keywords']);
        $this->assertNotContains($misspelledKeyword, $manifest['keywords']);
        $this->assertSame('EvolvePHP Community', $manifest['authors'][1]['name']);
    }

    public function testWorkspaceReadmeOwnsInstallUpdateAndQualityToolingPolicy(): void
    {
        $content = $this->readProjectFile('workspace/README.md');

        $this->assertStringContainsString('composer --working-dir=workspace install', $content);
        $this->assertStringContainsString('composer --working-dir=workspace update', $content);
        $this->assertMatchesPattern('/install.*normal|normal.*install/is', $content);
        $this->assertMatchesPattern('/update.*intentional|intentional.*update/is', $content);
        $this->assertMatchesPattern('/update.*changes dependency resolution|changes dependency resolution.*update/is', $content);

        foreach (array('validate', 'test', 'analyse', 'architecture', 'style:check', 'style:fix', 'quality') as $script) {
            $this->assertStringContainsString('composer --working-dir=workspace ' . $script, $content);
        }

        foreach (array('test:contracts', 'test:core', 'test:http', 'test:module', 'test:plugin', 'test:testing') as $script) {
            $this->assertStringContainsString('composer --working-dir=workspace ' . $script, $content);
        }
    }

    public function testCanonicalReadmesAvoidMachinePathsAndTemporaryProbeHistory(): void
    {
        foreach ($this->trackedReadmes() as $path) {
            $content = $this->readProjectFile($path);

            $this->assertStringNotContainsString('D:\\php-84\\php.exe', $content, $path);
            $this->assertDoesNotMatchPattern('/temporary forbidden-edge probe/i', $content);
        }
    }

    public function testPackagesReadmeLinksToWorkspaceAndDoesNotOwnDetailedDeptracPolicy(): void
    {
        $content = $this->readProjectFile('packages/README.md');

        $this->assertStringContainsString('workspace/README.md', $content);
        $this->assertDoesNotMatchPattern('/Contracts\s*->\s*\.\.\/packages\/contracts\/src\/\.\*\s*->\s*Evolve\\\\Contracts\\\\/', $content);
        $this->assertDoesNotMatchPattern('/Phase 2\.[0-9].*(?:adds|now provides|creates|verifies)/i', $content);
        $this->assertDoesNotMatchPattern('/Before Phase 2\.3/i', $content);
    }

    public function testWorkspaceReadmeOwnsCompleteArchitectureMatrix(): void
    {
        $content = $this->readProjectFile('workspace/README.md');

        foreach ($this->expectedArchitectureMatrixLines() as $line) {
            $this->assertStringContainsString($line, $content);
        }
    }

    public function testReadmesHaveNoDuplicateHeadings(): void
    {
        foreach ($this->trackedReadmes() as $path) {
            $headings = $this->markdownHeadings($this->readProjectFile($path));
            $duplicates = array_unique(array_diff_assoc($headings, array_unique($headings)));

            $this->assertSame(array(), array_values($duplicates), $path . ' contains duplicate headings.');
        }
    }

    public function testRfcReadmeIndexContainsEachAcceptedRfcExactlyOnce(): void
    {
        $content = $this->readProjectFile('docs/rfcs/README.md');
        $trackedFiles = $this->trackedFiles();

        for ($number = 1; $number <= 7; $number++) {
            $rfcNumber = sprintf('%04d', $number);
            $pattern = '/^- \[RFC ' . $rfcNumber . ':[^\]]+\]\(([^)]+)\) - Accepted$/m';

            $this->assertSame(1, preg_match_all($pattern, $content, $matches), 'RFC ' . $rfcNumber . ' must appear exactly once as an accepted index entry.');
            $linkedPath = 'docs/rfcs/' . $matches[1][0];

            $this->assertContains($linkedPath, $trackedFiles, $linkedPath . ' must be tracked.');
        }
    }

    public function testRfcReadmeDoesNotContainStaleAcceptedRfcNarration(): void
    {
        $content = $this->readProjectFile('docs/rfcs/README.md');
        $afterIndex = $this->markdownSection($content, 'Index');

        $this->assertDoesNotMatchPattern('/RFC 000[1-7] will define/i', $content);
        $this->assertDoesNotMatchPattern('/RFC 000[1-7] (?:defines|will define)/i', $afterIndex);
        $this->assertDoesNotMatchPattern('/RFC 0004 defines application\/module\/plugin lifecycle/i', $content);
        $this->assertDoesNotMatchPattern('/RFC 0005 defines execution scope, reset and context isolation/i', $content);
        $this->assertDoesNotMatchPattern('/RFC 0006 defines Bridge integration/i', $content);
        $this->assertDoesNotMatchPattern('/RFC 0007 defines Insight, generic instrumentation and OpenTelemetry architecture/i', $content);
    }

    public function testRelativeReadmeLinksPointToTrackedFiles(): void
    {
        $trackedFiles = $this->trackedFiles();

        foreach ($this->trackedReadmes() as $path) {
            $content = $this->readProjectFile($path);
            preg_match_all('/\[[^\]]+\]\(([^)]+)\)/', $content, $matches);

            foreach ($matches[1] as $target) {
                if ($this->isExternalLink($target)) {
                    continue;
                }

                $targetPath = preg_replace('/#.*/', '', $target);

                if ($targetPath === '') {
                    continue;
                }

                $resolved = $this->normalizeRelativePath(dirname(str_replace('\\', '/', $path)), $targetPath);

                $this->assertContains($resolved, $trackedFiles, $path . ' links to untracked file ' . $target . '.');
            }
        }
    }

    private function expectedArchitectureMatrixLines()
    {
        return array(
            'Contracts -> none',
            'Core      -> Contracts',
            'Http      -> Contracts, Core',
            'Module    -> Contracts',
            'Plugin    -> Contracts',
            'Testing   -> Contracts, Core, Http, Module, Plugin',
        );
    }

    private function trackedReadmes()
    {
        $readmes = array();

        foreach ($this->trackedFiles() as $file) {
            if (preg_match('/(?:^|\/)readme(?:\.[^\/]+)?$/i', $file)) {
                $readmes[] = $file;
            }
        }

        sort($readmes);

        return $readmes;
    }

    private function trackedFiles()
    {
        $output = array();
        $exitCode = 0;

        exec('git ls-files', $output, $exitCode);

        $this->assertSame(0, $exitCode, 'git ls-files should succeed.');

        return array_map(function ($file) {
            return str_replace('\\', '/', $file);
        }, $output);
    }

    private function markdownHeadings($content)
    {
        preg_match_all('/^(#{1,6})\s+(.+?)\s*$/m', $content, $matches);

        return array_map(function ($heading) {
            return strtolower(trim($heading));
        }, $matches[2]);
    }

    private function markdownSection($content, $heading)
    {
        $pattern = '/^##\s+' . preg_quote($heading, '/') . '\s*$(.*?)(?=^##\s+|\z)/ms';

        if (preg_match($pattern, $content, $match) !== 1) {
            return '';
        }

        return $match[1];
    }

    private function normalizeRelativePath($baseDirectory, $target)
    {
        $baseParts = $baseDirectory === '.' ? array() : explode('/', $baseDirectory);
        $targetParts = explode('/', str_replace('\\', '/', $target));
        $resolved = $baseParts;

        foreach ($targetParts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($resolved);
                continue;
            }

            $resolved[] = $part;
        }

        return implode('/', $resolved);
    }

    private function isExternalLink($target)
    {
        return preg_match('/^(?:https?:|mailto:)/i', $target) === 1;
    }

    private function readProjectFile($path)
    {
        $fullPath = $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
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
