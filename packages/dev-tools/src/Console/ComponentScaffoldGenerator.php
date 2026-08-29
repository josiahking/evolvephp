<?php

declare(strict_types=1);

namespace Evolve\DevTools\Console;

use Evolve\Contracts\Component\ComponentIdentifier;
use InvalidArgumentException;
use RuntimeException;

/**
 * @internal
 */
final readonly class ComponentScaffoldGenerator
{
    public function __construct(private string $projectRoot)
    {
        $realProjectRoot = realpath($projectRoot);

        if ($realProjectRoot === false || ! is_dir($realProjectRoot)) {
            throw new InvalidArgumentException('Project root must be an existing directory.');
        }
    }

    /**
     * @return array{identifier: string, paths: list<string>}
     */
    public function generateModule(string $name): array
    {
        $identifier = self::identifierFor($name);
        $paths = [
            'src/Modules/' . $name . '/' . $name . 'Module.php',
            'src/Modules/' . $name . '/module.php',
            'tests/Modules/' . $name . '/' . $name . 'ModuleTest.php',
        ];

        $this->writeAll([
            $paths[0] => $this->moduleClass($name),
            $paths[1] => $this->moduleDefinition($name, $identifier),
            $paths[2] => $this->moduleTest($name),
        ]);

        return ['identifier' => $identifier, 'paths' => $paths];
    }

    /**
     * @return array{identifier: string, paths: list<string>}
     */
    public function generatePlugin(string $name): array
    {
        $identifier = self::identifierFor($name);
        $paths = [
            'src/Plugins/' . $name . '/' . $name . 'Plugin.php',
            'src/Plugins/' . $name . '/plugin.php',
            'tests/Plugins/' . $name . '/' . $name . 'PluginTest.php',
        ];

        $this->writeAll([
            $paths[0] => $this->pluginClass($name),
            $paths[1] => $this->pluginDefinition($name, $identifier),
            $paths[2] => $this->pluginTest($name),
        ]);

        return ['identifier' => $identifier, 'paths' => $paths];
    }

    public static function isValidName(string $name): bool
    {
        return preg_match('/^[A-Z][A-Za-z0-9]*$/', $name) === 1;
    }

    private static function identifierFor(string $name): string
    {
        $slug = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $name));
        $identifier = 'app/' . $slug;

        new ComponentIdentifier($identifier);

        return $identifier;
    }

    /**
     * @param array<string, string> $files
     */
    private function writeAll(array $files): void
    {
        $createdFiles = [];
        $createdDirectories = [];

        foreach (array_keys($files) as $relativePath) {
            $this->assertSafeRelativePath($relativePath);

            if (file_exists($this->absolutePath($relativePath))) {
                throw new RuntimeException('Refusing to overwrite existing file: ' . $relativePath);
            }
        }

        try {
            foreach ($files as $relativePath => $content) {
                $absolutePath = $this->absolutePath($relativePath);
                $directory = dirname($absolutePath);

                if (! is_dir($directory)) {
                    $this->createDirectory($directory, $createdDirectories);
                }

                $temporaryPath = $directory . DIRECTORY_SEPARATOR . '.' . basename($absolutePath) . '.' . bin2hex(random_bytes(6)) . '.tmp';
                $handle = fopen($temporaryPath, 'x');

                if ($handle === false) {
                    throw new RuntimeException('Unable to create temporary scaffold file.');
                }

                $bytes = fwrite($handle, $content);

                if ($bytes !== strlen($content) || ! fclose($handle)) {
                    @unlink($temporaryPath);

                    throw new RuntimeException('Unable to write scaffold file.');
                }

                if (! rename($temporaryPath, $absolutePath)) {
                    @unlink($temporaryPath);

                    throw new RuntimeException('Unable to publish scaffold file.');
                }

                $createdFiles[] = $absolutePath;
            }
        } catch (\Throwable $exception) {
            foreach (array_reverse($createdFiles) as $createdFile) {
                if (is_file($createdFile)) {
                    @unlink($createdFile);
                }
            }

            foreach (array_reverse($createdDirectories) as $createdDirectory) {
                if (is_dir($createdDirectory)) {
                    @rmdir($createdDirectory);
                }
            }

            throw $exception;
        }
    }

    /**
     * @param list<string> $createdDirectories
     */
    private function createDirectory(string $directory, array &$createdDirectories): void
    {
        $segments = explode(DIRECTORY_SEPARATOR, substr($directory, strlen($this->projectRoot()) + 1));
        $current = $this->projectRoot();

        foreach ($segments as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;

            if (is_dir($current)) {
                continue;
            }

            if (file_exists($current)) {
                throw new RuntimeException('Scaffold target parent is not a directory.');
            }

            if (! mkdir($current)) {
                throw new RuntimeException('Unable to create scaffold directory.');
            }

            $createdDirectories[] = $current;
        }
    }

    private function assertSafeRelativePath(string $relativePath): void
    {
        $parts = explode('/', $relativePath);
        $current = $this->projectRoot();

        foreach (array_slice($parts, 0, -1) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException('Refusing to write outside project root.');
            }

            $candidate = $current . DIRECTORY_SEPARATOR . $part;

            if (! file_exists($candidate)) {
                $current = $candidate;
                continue;
            }

            $realCandidate = realpath($candidate);

            if ($realCandidate === false || ! str_starts_with($realCandidate, $this->projectRoot() . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Refusing to write outside project root.');
            }

            if (! is_dir($candidate)) {
                throw new RuntimeException('Scaffold target parent is not a directory.');
            }

            $current = $realCandidate;
        }
    }

    private function absolutePath(string $relativePath): string
    {
        return $this->projectRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }

    private function projectRoot(): string
    {
        $realProjectRoot = realpath($this->projectRoot);

        if ($realProjectRoot === false || ! is_dir($realProjectRoot)) {
            throw new RuntimeException('Project root must remain an existing directory.');
        }

        return $realProjectRoot;
    }

    private function moduleClass(string $name): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Modules\\$name;

use Evolve\Contracts\Component\ComponentBootContext;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Module\Module;

final class {$name}Module implements Module
{
    public function register(ServiceDefinitionRegistrar \$registrar): void {}

    public function boot(ComponentBootContext \$context): void {}

    public function ready(): void {}

    public function shutdown(): void {}
}

PHP;
    }

    private function moduleDefinition(string $name, string $identifier): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use App\Modules\\$name\\{$name}Module;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Module\ModuleDefinition;
use Evolve\Module\ModuleDescriptor;

return new ModuleDefinition(
    new ModuleDescriptor(
        new ComponentIdentifier('$identifier'),
        '$name',
        2,
    ),
    {$name}Module::class,
);

PHP;
    }

    private function moduleTest(string $name): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace Tests\Modules\\$name;

use App\Modules\\$name\\{$name}Module;
use Evolve\Contracts\Component\ComponentBootContext;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Module\Module;
use PHPUnit\Framework\TestCase;

final class {$name}ModuleTest extends TestCase
{
    public function testModuleImplementsEvolveModuleContract(): void
    {
        self::assertInstanceOf(Module::class, new {$name}Module());
    }

    public function testLifecycleMethodsAreCallable(): void
    {
        \$module = new {$name}Module();

        \$module->register(\$this->createStub(ServiceDefinitionRegistrar::class));
        \$module->boot(\$this->createStub(ComponentBootContext::class));
        \$module->ready();
        \$module->shutdown();

        self::addToAssertionCount(1);
    }
}

PHP;
    }

    private function pluginClass(string $name): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace App\Plugins\\$name;

use Evolve\Contracts\Component\ComponentBootContext;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Plugin\Plugin;

final class {$name}Plugin implements Plugin
{
    public function register(ServiceDefinitionRegistrar \$registrar): void {}

    public function boot(ComponentBootContext \$context): void {}

    public function ready(): void {}

    public function shutdown(): void {}
}

PHP;
    }

    private function pluginDefinition(string $name, string $identifier): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

use App\Plugins\\$name\\{$name}Plugin;
use Evolve\Contracts\Component\ComponentIdentifier;
use Evolve\Plugin\PluginDefinition;
use Evolve\Plugin\PluginDescriptor;

return new PluginDefinition(
    new PluginDescriptor(
        new ComponentIdentifier('$identifier'),
        '$name',
        2,
    ),
    {$name}Plugin::class,
);

PHP;
    }

    private function pluginTest(string $name): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace Tests\Plugins\\$name;

use App\Plugins\\$name\\{$name}Plugin;
use Evolve\Contracts\Component\ComponentBootContext;
use Evolve\Contracts\Component\Registration\ServiceDefinitionRegistrar;
use Evolve\Plugin\Plugin;
use PHPUnit\Framework\TestCase;

final class {$name}PluginTest extends TestCase
{
    public function testPluginImplementsEvolvePluginContract(): void
    {
        self::assertInstanceOf(Plugin::class, new {$name}Plugin());
    }

    public function testLifecycleMethodsAreCallable(): void
    {
        \$plugin = new {$name}Plugin();

        \$plugin->register(\$this->createStub(ServiceDefinitionRegistrar::class));
        \$plugin->boot(\$this->createStub(ComponentBootContext::class));
        \$plugin->ready();
        \$plugin->shutdown();

        self::addToAssertionCount(1);
    }
}

PHP;
    }
}
