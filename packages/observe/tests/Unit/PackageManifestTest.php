<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PackageManifestTest extends TestCase
{
    public function testPackageManifestDeclaresObserveIdentityAndDependencyPolicy(): void
    {
        $manifest = json_decode(
            file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('evolvephp/observe', $manifest['name']);
        $this->assertSame('OpenTelemetry composition foundation for EvolvePHP 2.', $manifest['description']);
        $this->assertSame('library', $manifest['type']);
        $this->assertSame('BSD-3-Clause', $manifest['license']);
        $this->assertSame(['php' => '^8.4', 'open-telemetry/api' => '^1.10'], $manifest['require']);
        $this->assertArrayHasKey('open-telemetry/sdk', $manifest['suggest']);
        $this->assertSame(['Evolve\\Observe\\' => 'src/'], $manifest['autoload']['psr-4']);
        $this->assertArrayNotHasKey('bin', $manifest);
        $this->assertArrayNotHasKey('evolvephp/core', $manifest['require']);
        $this->assertArrayNotHasKey('evolvephp/insight', $manifest['require']);
        $this->assertArrayNotHasKey('open-telemetry/sdk', $manifest['require']);
    }
}
