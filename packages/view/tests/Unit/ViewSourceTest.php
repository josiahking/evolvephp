<?php

declare(strict_types=1);

namespace Evolve\View\Tests\Unit;

use Evolve\View\Exception\InvalidViewSource;
use Evolve\View\ViewSource;
use PHPUnit\Framework\TestCase;

final class ViewSourceTest extends TestCase
{
    public function test_existing_absolute_directory_is_canonicalized(): void
    {
        $source = new ViewSource('billing', sys_get_temp_dir());
        self::assertSame(realpath(sys_get_temp_dir()), $source->root());
        self::assertSame('billing', $source->namespace());
    }

    public function test_nonexistent_directory_is_rejected(): void
    {
        $this->expectException(InvalidViewSource::class);
        new ViewSource(null, sys_get_temp_dir() . '/missing-' . bin2hex(random_bytes(8)));
    }

    public function test_relative_directory_is_rejected(): void
    {
        $this->expectException(InvalidViewSource::class);
        new ViewSource(null, '.');
    }

    public function test_file_root_is_rejected(): void
    {
        $this->expectException(InvalidViewSource::class);
        new ViewSource(null, __FILE__);
    }

    public function test_invalid_namespace_is_rejected(): void
    {
        $this->expectException(InvalidViewSource::class);
        new ViewSource('Billing', sys_get_temp_dir());
    }
}
