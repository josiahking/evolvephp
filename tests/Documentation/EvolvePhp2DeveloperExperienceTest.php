<?php

use PHPUnit\Framework\TestCase;

final class EvolvePhp2DeveloperExperienceTest extends TestCase
{
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testRequiredRepositoryEditorAssetsExist(): void
    {
        foreach (array(
            '.editorconfig',
            '.vscode/extensions.json',
            '.vscode/settings.json',
            '.vscode/tasks.json',
        ) as $path) {
            $this->assertFileExists($this->projectPath($path), $path . ' should be committed for the developer-experience foundation.');
        }
    }

    public function testVsCodeConfigurationFilesContainValidJson(): void
    {
        foreach (array(
            '.vscode/extensions.json',
            '.vscode/settings.json',
            '.vscode/tasks.json',
        ) as $path) {
            $this->readJsonFile($path);
        }
    }

    public function testEditorConfigDeclaresPortableWhitespacePolicy(): void
    {
        $content = $this->readProjectFile('.editorconfig');

        $this->assertMatchesPattern('/^root\s*=\s*true\s*$/m', $content);
        $this->assertMatchesPattern('/^\[\*\]\s*$/m', $content);
        $this->assertMatchesPattern('/^charset\s*=\s*utf-8\s*$/mi', $content);
        $this->assertMatchesPattern('/^end_of_line\s*=\s*lf\s*$/m', $content);
        $this->assertMatchesPattern('/^insert_final_newline\s*=\s*true\s*$/m', $content);
        $this->assertMatchesPattern('/^indent_style\s*=\s*space\s*$/m', $content);
        $this->assertMatchesPattern('/^\[\*\.php\]\s*\R(?:.*\R)*?^indent_size\s*=\s*4\s*$/m', $content);
        $this->assertMatchesPattern('/^\[\*\.\{json,jsonc\}\]\s*\R(?:.*\R)*?^indent_size\s*=\s*4\s*$/m', $content);
        $this->assertMatchesPattern('/^\[\*\.\{ya?ml,yml\}\]\s*\R(?:.*\R)*?^indent_size\s*=\s*2\s*$/m', $content);
        $this->assertDoesNotMatchPattern('/trim_trailing_whitespace\s*=\s*true/i', $content);
    }

    public function testRecommendedVsCodeExtensionsAreMinimalPhpEditorBaseline(): void
    {
        $extensions = $this->readJsonFile('.vscode/extensions.json');

        $this->assertSame(
            array(
                'bmewburn.vscode-intelephense-client',
                'editorconfig.editorconfig',
            ),
            $extensions['recommendations']
        );
        $this->assertArrayNotHasKey('unwantedRecommendations', $extensions);
    }

    public function testVsCodeSettingsTargetPhp84AndAvoidLocalRuntimeConfiguration(): void
    {
        $settings = $this->readJsonFile('.vscode/settings.json');
        $content = $this->readProjectFile('.vscode/settings.json');

        $this->assertSame('8.4.0', $settings['intelephense.environment.phpVersion']);
        $this->assertArrayHasKey('files.watcherExclude', $settings);
        $this->assertArrayHasKey('search.exclude', $settings);

        foreach (array(
            '**/vendor/**',
            '**/.phpstan-cache/**',
            '**/.php-cs-fixer.cache',
            '**/.deptrac.cache',
        ) as $excludedPath) {
            $this->assertSame(true, $settings['files.watcherExclude'][$excludedPath], $excludedPath . ' should be excluded from file watching.');
            $this->assertSame(true, $settings['search.exclude'][$excludedPath], $excludedPath . ' should be excluded from search.');
        }

        foreach (array(
            '/php(?:\.validate)?\.executablePath/i',
            '/composer(?:\.executable|Path)/i',
            '/formatOnSave/i',
            '/defaultFormatter/i',
            '/php-cs-fixer\.(?:extension|executable|onsave|path)/i',
            '/xdebug/i',
            '/launch\.json/i',
            '/"debug\./i',
            '/[A-Za-z]:\\\\/',
            '#/(?:d/php|d/tools|Users|home)/#i',
        ) as $pattern) {
            $this->assertDoesNotMatchPattern($pattern, $content);
        }
    }

    public function testVsCodeTasksWrapExistingComposerScriptsAndRootPolicyCommand(): void
    {
        $tasksFile = $this->readJsonFile('.vscode/tasks.json');

        $this->assertSame('2.0.0', $tasksFile['version']);
        $this->assertArrayHasKey('tasks', $tasksFile);

        $tasks = $this->tasksByLabel($tasksFile['tasks']);

        $expectedComposerTasks = array(
            'EvolvePHP 2: Install Dependencies' => 'install',
            'EvolvePHP 2: Quality' => 'quality',
            'EvolvePHP 2: Tests' => 'test',
            'EvolvePHP 2: Architecture' => 'architecture',
            'EvolvePHP 2: Static Analysis' => 'analyse',
            'EvolvePHP 2: Style Check' => 'style:check',
            'EvolvePHP 2: Style Fix' => 'style:fix',
        );

        foreach ($expectedComposerTasks as $label => $script) {
            $this->assertArrayHasKey($label, $tasks);
            $this->assertSame('shell', $tasks[$label]['type']);
            $this->assertSame('composer', $tasks[$label]['command']);
            $this->assertSame(array($script), $tasks[$label]['args']);
        }

        $this->assertArrayHasKey('EvolvePHP 2: Root Policy', $tasks);
        $this->assertSame('shell', $tasks['EvolvePHP 2: Root Policy']['type']);
        $this->assertSame('php', $tasks['EvolvePHP 2: Root Policy']['command']);
        $this->assertSame(
            array(
                'vendor/bin/phpunit',
                '--configuration',
                'phpunit.xml.dist',
                'tests/Architecture',
                'tests/Documentation',
            ),
            $tasks['EvolvePHP 2: Root Policy']['args']
        );
    }

    public function testVsCodeTasksAvoidMachineSpecificExecutablesAndAutomaticMutation(): void
    {
        $content = $this->readProjectFile('.vscode/tasks.json');

        foreach (array(
            '/[A-Za-z]:\\\\/',
            '#/(?:d/php|d/tools|Users|home)/#i',
            '/D:\\\\php-84/i',
            '/D:\\\\tools/i',
            '/C:\\\\/i',
            '/xdebug/i',
            '/launch\.json/i',
            '/pre-commit|git hook/i',
        ) as $pattern) {
            $this->assertDoesNotMatchPattern($pattern, $content);
        }

        $tasks = $this->tasksByLabel($this->readJsonFile('.vscode/tasks.json')['tasks']);

        foreach ($tasks as $label => $task) {
            $this->assertArrayNotHasKey('runOptions', $task, $label . ' should not auto-run.');
        }
    }

    public function testDocumentationDescribesPortableOptionalDeveloperExperienceContract(): void
    {
        $combined = $this->readProjectFile('README.md')
            . "\n"
            . $this->readProjectFile('DEVELOPMENT.md');

        foreach (array(
            '/VS Code.*optional developer tooling|optional developer tooling.*VS Code/i',
            '/not framework runtime|framework runtime.*not/i',
            '/PHP 8\.4\+.*Composer|Composer.*PHP 8\.4\+/i',
            '/composer\.json.*canonical|canonical.*composer\.json/i',
            '/Composer root scripts.*canonical|canonical.*Composer root scripts/i',
            '/tasks.*convenience wrappers|convenience wrappers.*tasks/i',
            '/Style Fix.*mutating|mutating.*Style Fix/i',
            '/Quality.*non-mutating|non-mutating.*Quality/i',
            '/Tests.*non-mutating|non-mutating.*Tests/i',
            '/Architecture.*non-mutating|non-mutating.*Architecture/i',
            '/Static Analysis.*non-mutating|non-mutating.*Static Analysis/i',
            '/Style Check.*non-mutating|non-mutating.*Style Check/i',
            '/Root Policy.*non-mutating|non-mutating.*Root Policy/i',
            '/no machine-specific executable|machine-specific executable.*not/i',
            '/multiple PHP versions.*PHP 8\.4\+|PHP 8\.4\+.*multiple PHP versions/i',
            '/integrated terminal.*PHP 8\.4\+|PHP 8\.4\+.*integrated terminal/i',
            '/runtime.*debugging.*deferred|debugging.*deferred.*runtime/i',
        ) as $pattern) {
            $this->assertMatchesPattern($pattern, $combined);
        }

        foreach (array(
            '/D:\\\\php-84/i',
            '/D:\\\\tools/i',
            '/[A-Za-z]:\\\\/',
            '#/(?:d/php|d/tools|Users|home)/#i',
            '/Xdebug.*configured|configured.*Xdebug/i',
        ) as $pattern) {
            $this->assertDoesNotMatchPattern($pattern, $combined);
        }
    }

    public function testChangelogRecordsPhase28DeveloperExperienceFoundation(): void
    {
        $content = $this->readProjectFile('CHANGELOG.md');

        foreach (array(
            '/Phase 2\.8/i',
            '/EditorConfig/i',
            '/VS Code/i',
            '/PHP 8\.4.*language-analysis|language-analysis.*PHP 8\.4/i',
            '/portable task commands|task commands.*portable/i',
            '/Composer-script reuse|Composer scripts.*canonical/i',
            '/non-mutating.*Style Fix.*mutating|mutating.*Style Fix.*non-mutating/i',
            '/no local executable paths|local executable paths.*no/i',
            '/no runtime debugging configuration|runtime debugging configuration.*no/i',
        ) as $pattern) {
            $this->assertMatchesPattern($pattern, $content);
        }
    }

    public function testLaunchConfigurationIsNotCreatedDuringThisPhase(): void
    {
        $this->assertFileDoesNotExist($this->projectPath('.vscode/launch.json'));
    }

    private function tasksByLabel($tasks)
    {
        $this->assertIsArray($tasks);

        $indexed = array();

        foreach ($tasks as $task) {
            $this->assertArrayHasKey('label', $task);
            $this->assertArrayNotHasKey($task['label'], $indexed, $task['label'] . ' should be unique.');

            $indexed[$task['label']] = $task;
        }

        ksort($indexed);

        return $indexed;
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
