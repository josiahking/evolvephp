<?php

declare(strict_types=1);

namespace Evolve\View\Twig\Tests\Unit;

use Evolve\View\Exception\ViewNotFound;
use Evolve\View\Twig\TwigViewPathResolver;
use Evolve\View\ViewName;
use Evolve\View\ViewSource;
use PHPUnit\Framework\TestCase;

final class TwigViewPathResolverTest extends TestCase
{
    public function test_source_precedence_namespace_isolation_and_extension(): void
    {
        $first = sys_get_temp_dir() . '/evolve-twig-' . bin2hex(random_bytes(8));
        $second = sys_get_temp_dir() . '/evolve-twig-' . bin2hex(random_bytes(8));
        $other = sys_get_temp_dir() . '/evolve-twig-' . bin2hex(random_bytes(8));
        mkdir($first);
        mkdir($second);
        mkdir($other);
        try {
            file_put_contents($first . '/page.html.twig', 'first');
            file_put_contents($second . '/page.html.twig', 'second');
            file_put_contents($other . '/page.html.twig', 'other');
            $resolver = new TwigViewPathResolver([new ViewSource('shipping', $other), new ViewSource('billing', $first), new ViewSource('billing', $second)]);
            self::assertSame(realpath($first . '/page.html.twig'), $resolver->resolve(new ViewName('billing::page')));
            self::assertSame(realpath($other . '/page.html.twig'), $resolver->resolve(new ViewName('shipping::page')));
            unlink($first . '/page.html.twig');
            self::assertSame(realpath($second . '/page.html.twig'), $resolver->resolve(new ViewName('billing::page')));
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
        $root = sys_get_temp_dir() . '/evolve-twig-' . bin2hex(random_bytes(8));
        mkdir($root);
        try {
            file_put_contents($root . '/page.twig', 'wrong');
            $resolver = new TwigViewPathResolver([new ViewSource(null, $root)]);
            try {
                $resolver->resolve(new ViewName('page'));
                self::fail('Expected missing Twig view.');
            } catch (ViewNotFound) {
            }
            file_put_contents($root . '/page.html.twig', 'right');
            self::assertSame(realpath($root . '/page.html.twig'), $resolver->resolve(new ViewName('page')));
        } finally {
            foreach (glob($root . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }

    public function test_symlink_escape_is_rejected(): void
    {
        $root = sys_get_temp_dir() . '/evolve-twig-' . bin2hex(random_bytes(8));
        $outside = sys_get_temp_dir() . '/evolve-twig-' . bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($outside);
        try {
            file_put_contents($outside . '/escape.html.twig', 'outside');
            if (!@symlink($outside . '/escape.html.twig', $root . '/escape.html.twig')) {
                if (PHP_OS_FAMILY === 'Windows') {
                    self::markTestSkipped('Windows account cannot create a file symlink.');
                }
                self::fail('Linux must create the symlink.');
            }
            $this->expectException(ViewNotFound::class);
            (new TwigViewPathResolver([new ViewSource(null, $root)]))->resolve(new ViewName('escape'));
        } finally {
            if (is_link($root . '/escape.html.twig')) {
                unlink($root . '/escape.html.twig');
            }
            unlink($outside . '/escape.html.twig');
            rmdir($outside);
            rmdir($root);
        }
    }
}
