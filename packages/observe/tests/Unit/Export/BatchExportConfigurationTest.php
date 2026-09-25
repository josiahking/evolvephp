<?php

declare(strict_types=1);

namespace Evolve\Observe\Tests\Unit\Export;

use Evolve\Observe\Export\BatchExportConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class BatchExportConfigurationTest extends TestCase
{
    public function testValidFiniteConfigurationIsExposedThroughTypedGetters(): void
    {
        $configuration = new BatchExportConfiguration(
            maxQueueSize: 16,
            maxExportBatchSize: 4,
            scheduledDelayMillis: 250,
            autoFlush: false,
        );

        $this->assertSame(16, $configuration->maxQueueSize());
        $this->assertSame(4, $configuration->maxExportBatchSize());
        $this->assertSame(250, $configuration->scheduledDelayMillis());
        $this->assertFalse($configuration->autoFlush());
    }

    public function testDefaultsAreFiniteAndExplicit(): void
    {
        $configuration = new BatchExportConfiguration();

        $this->assertSame(2048, $configuration->maxQueueSize());
        $this->assertSame(512, $configuration->maxExportBatchSize());
        $this->assertSame(5000, $configuration->scheduledDelayMillis());
        $this->assertTrue($configuration->autoFlush());
    }

    /**
     * @return iterable<string, array{callable(): BatchExportConfiguration}>
     */
    public static function invalidPositiveNumericDimensions(): iterable
    {
        yield 'queue size zero' => [static fn(): BatchExportConfiguration => new BatchExportConfiguration(maxQueueSize: 0)];
        yield 'queue size negative' => [static fn(): BatchExportConfiguration => new BatchExportConfiguration(maxQueueSize: -1)];
        yield 'export batch size zero' => [static fn(): BatchExportConfiguration => new BatchExportConfiguration(maxExportBatchSize: 0)];
        yield 'export batch size negative' => [static fn(): BatchExportConfiguration => new BatchExportConfiguration(maxExportBatchSize: -1)];
        yield 'scheduled delay zero' => [static fn(): BatchExportConfiguration => new BatchExportConfiguration(scheduledDelayMillis: 0)];
        yield 'scheduled delay negative' => [static fn(): BatchExportConfiguration => new BatchExportConfiguration(scheduledDelayMillis: -1)];
    }

    /**
     * @param callable(): BatchExportConfiguration $createConfiguration
     *
     */
    #[DataProvider('invalidPositiveNumericDimensions')]
    public function testPositiveNumericDimensionsRejectZeroAndNegativeValues(callable $createConfiguration): void
    {
        $this->expectException(InvalidArgumentException::class);

        $createConfiguration();
    }

    public function testExportBatchSizeMustNotExceedQueueSize(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BatchExportConfiguration(maxQueueSize: 4, maxExportBatchSize: 5);
    }

    public function testConfigurationIsFinalReadonlyAndDoesNotExposeHardExportTimeout(): void
    {
        $reflection = new ReflectionClass(BatchExportConfiguration::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
        $this->assertFalse($reflection->hasMethod('exportTimeoutMillis'));
    }
}
