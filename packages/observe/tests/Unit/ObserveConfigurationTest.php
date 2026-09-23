<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit;

use Evolve\Observe\ObserveConfiguration;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ObserveConfigurationTest extends TestCase
{
    public function testConfigurationIsFinalReadonlyAndDisabledByDefault(): void
    {
        $reflection = new ReflectionClass(ObserveConfiguration::class);
        $configuration = new ObserveConfiguration();

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
        $this->assertFalse($configuration->isEnabled());
    }

    public function testConfigurationCanBeExplicitlyEnabledOrDisabled(): void
    {
        $this->assertTrue((new ObserveConfiguration(enabled: true))->isEnabled());
        $this->assertFalse((new ObserveConfiguration(enabled: false))->isEnabled());
    }
}
