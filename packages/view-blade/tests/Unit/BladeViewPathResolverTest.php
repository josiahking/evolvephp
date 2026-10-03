<?php

declare(strict_types=1);

namespace Evolve\View\Blade\Tests\Unit;

use Evolve\View\Blade\BladeViewPathResolver;
use Evolve\View\Exception\ViewNotFound;
use Evolve\View\ViewName;
use Evolve\View\ViewSource;
use PHPUnit\Framework\TestCase;

final class BladeViewPathResolverTest extends TestCase
{
    public function test_source_precedence_namespace_isolation_and_extension(): void
    {
        $first = sys_get_temp_dir() . '/evolve-blade-' . bin2hex(random_bytes(8));
        $second = sys_get_temp_dir() . '/evolve-blade-' . bin2hex(random_bytes(8));
        $other = sys_get_temp_dir() . '/evolve-blade-' . bin2hex(random_bytes(8));
        mkdir($first);
        mkdir($second);
        mkdir($other);
        try {
            file_put_contents($first . '/page.blade.php', 'first');
            file_put_contents($second . '/page.blade.php', 'second');
            file_put_contents($other . '/page.blade.php', 'other');
            $resolver = new BladeViewPathResolver([new ViewSource('shipping', $other), new ViewSource('billing', $first), new ViewSource('billing', $second)]);
            self::assertSame(realpath($first . '/page.blade.php'), $resolver->resolve(new ViewName('billing::page')));
            self::assertSame(realpath($other . '/page.blade.php'), $resolver->resolve(new ViewName('shipping::page')));
            unlink($first . '/page.blade.php');
            self::assertSame(realpath($second . '/page.blade.php'), $resolver->resolve(new ViewName('billing::page')));
            $this->expectException(ViewNotFound::class);
            $resolver->resolve(new ViewName('page'));
        } finally {
            foreach ([$first, $second, $other] as $root) {
                foreach (glob($root . '/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($root);
            }
        }
    }

    public function test_miss_is_not_cached_and_wrong_extension_is_ignored(): void
    {
        $root = sys_get_temp_dir() . '/evolve-blade-' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            file_put_contents($root . '/page.php', 'wrong');
            $resolver = new BladeViewPathResolver([new ViewSource(null, $root)]);
            try {
                $resolver->resolve(new ViewName('page'));
                self::fail('Expected missing Blade view.');
            } catch (ViewNotFound) {
            }
            file_put_contents($root . '/page.blade.php', 'right');
            self::assertSame(realpath($root . '/page.blade.php'), $resolver->resolve(new ViewName('page')));
        } finally {
            foreach (glob($root . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }

    public function test_symlink_escape_is_rejected(): void
    {
        $root = sys_get_temp_dir() . '/evolve-blade-' . bin2hex(random_bytes(8));
        $outside = sys_get_temp_dir() . '/evolve-blade-' . bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($outside);
        try {
            file_put_contents($outside . '/escape.blade.php', 'outside');
            if (!@symlink($outside . '/escape.blade.php', $root . '/escape.blade.php')) {
                if (PHP_OS_FAMILY === 'Windows') {
                    self::markTestSkipped('Windows account cannot create a file symlink.');
                }
                self::fail('Linux must create the symlink.');
            }
            $this->expectException(ViewNotFound::class);
            (new BladeViewPathResolver([new ViewSource(null, $root)]))->resolve(new ViewName('escape'));
        } finally {
            if (is_link($root . '/escape.blade.php')) {
                unlink($root . '/escape.blade.php');
            }
            unlink($outside . '/escape.blade.php');
            rmdir($outside);
            rmdir($root);
        }
    }
}
