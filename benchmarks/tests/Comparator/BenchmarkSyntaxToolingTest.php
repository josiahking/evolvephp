<?php

declare(strict_types=1);

namespace Evolve\Benchmarks\Tests\Comparator;

use PHPUnit\Framework\TestCase;

final class BenchmarkSyntaxToolingTest extends TestCase
{
    public function testSyntaxCheckerIncludesComparatorCodeAndSkipsVendorTrees(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/bin/check-syntax.php');

        self::assertIsString($script);
        self::assertStringContainsString("'comparators'", $script);
        self::assertStringContainsString("'vendor'", $script);
    }
}
