<?php

declare(strict_types=1);

namespace Evolve\View\Tests\Unit;

use Evolve\View\Exception\ViewNotFound;
use Evolve\View\FilesystemViewPathResolver;
use Evolve\View\ViewName;
use Evolve\View\ViewSource;
use PHPUnit\Framework\TestCase;

final class FilesystemViewPathResolverTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            foreach (glob($root . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($root);
        }
    }

    public function test_first_matching_namespaced_source_wins_and_global_is_isolated(): void
    {
        $app = $this->root();
        $module = $this->root();
        $global = $this->root();
        file_put_contents($app . '/show.php', 'app');
        file_put_contents($module . '/show.php', 'module');
        file_put_contents($global . '/show.php', 'global');
        $resolver = new FilesystemViewPathResolver([
            new ViewSource(null, $global),
            new ViewSource('billing', $app),
            new ViewSource('billing', $module),
        ]);
        self::assertSame(realpath($app . '/show.php'), $resolver->resolve(new ViewName('billing::show')));
        self::assertSame(realpath($global . '/show.php'), $resolver->resolve(new ViewName('show')));
        unlink($app . '/show.php');
        self::assertSame(realpath($module . '/show.php'), $resolver->resolve(new ViewName('billing::show')));
    }

    public function test_missing_namespaced_view_never_falls_back_to_global(): void
    {
        $global = $this->root();
        file_put_contents($global . '/show.php', 'global');
        $this->expectException(ViewNotFound::class);
        (new FilesystemViewPathResolver([new ViewSource(null, $global)]))->resolve(new ViewName('billing::show'));
    }

    public function test_cached_result_revalidates_precedence_and_symlink_target(): void
    {
        $app = $this->root();
        $module = $this->root();
        file_put_contents($module . '/show.php', 'module');
        $resolver = new FilesystemViewPathResolver([new ViewSource(null, $app), new ViewSource(null, $module)]);
        self::assertSame(realpath($module . '/show.php'), $resolver->resolve(new ViewName('show')));
        self::assertSame(['show' => realpath($module . '/show.php')], (new \ReflectionProperty($resolver, 'paths'))->getValue($resolver));
        file_put_contents($app . '/show.php', 'app');
        self::assertSame(realpath($app . '/show.php'), $resolver->resolve(new ViewName('show')));
        unlink($app . '/show.php');
        self::assertSame(realpath($module . '/show.php'), $resolver->resolve(new ViewName('show')));
    }

    public function test_candidate_requires_exact_php_extension(): void
    {
        $root = $this->root();
        file_put_contents($root . '/show.phtml', 'wrong extension');
        try {
            $this->expectException(ViewNotFound::class);
            (new FilesystemViewPathResolver([new ViewSource(null, $root)]))->resolve(new ViewName('show'));
        } finally {
            unlink($root . '/show.phtml');
        }
    }

    public function test_symlink_outside_root_is_rejected(): void
    {
        $root = $this->root();
        $outside = $this->root();
        file_put_contents($outside . '/escape.php', 'outside');
        if (!@symlink($outside . '/escape.php', $root . '/escape.php')) {
            if (PHP_OS_FAMILY === 'Windows') {
                self::markTestSkipped('Windows account cannot create a file symlink.');
            }
            self::fail('Linux CI must create the symlink.');
        }
        $this->expectException(ViewNotFound::class);
        (new FilesystemViewPathResolver([new ViewSource(null, $root)]))->resolve(new ViewName('escape'));
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/evolve-view-' . bin2hex(random_bytes(8));
        mkdir($root);
        $this->roots[] = $root;
        return $root;
    }
}
