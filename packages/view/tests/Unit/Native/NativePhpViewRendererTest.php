<?php

declare(strict_types=1);

namespace Evolve\View\Tests\Unit\Native;

use Evolve\View\Exception\ViewNotFound;
use Evolve\View\Exception\ViewRenderFailed;
use Evolve\View\FilesystemViewPathResolver;
use Evolve\View\Native\NativePhpViewRenderer;
use Evolve\View\ViewSource;
use PHPUnit\Framework\TestCase;

final class NativePhpViewRendererTest extends TestCase
{
    public function test_render_escapes_and_isolates_data(): void
    {
        $root = sys_get_temp_dir() . '/evolve-view-' . bin2hex(random_bytes(8));
        mkdir($root);
        file_put_contents($root . '/home.php', '<?php echo $view->text($view->data("message", "missing"));');
        try {
            $renderer = new NativePhpViewRenderer(new FilesystemViewPathResolver([new ViewSource(null, $root)]));
            self::assertSame('&lt;&amp;&gt;', $renderer->render('home', ['message' => '<&>']));
            self::assertSame('missing', $renderer->render('home'));
        } finally {
            unlink($root . '/home.php');
            rmdir($root);
        }
    }

    public function test_failure_wraps_original_and_restores_buffers(): void
    {
        $root = sys_get_temp_dir() . '/evolve-view-' . bin2hex(random_bytes(8));
        mkdir($root);
        file_put_contents($root . '/fail.php', '<?php ob_start(); throw new \\RuntimeException("failure");');
        try {
            $renderer = new NativePhpViewRenderer(new FilesystemViewPathResolver([new ViewSource(null, $root)]));
            $level = ob_get_level();
            try {
                $renderer->render('fail');
                self::fail('Render should fail.');
            } catch (ViewRenderFailed $error) {
                self::assertInstanceOf(\RuntimeException::class, $error->getPrevious());
                self::assertSame($level, ob_get_level());
            }
        } finally {
            unlink($root . '/fail.php');
            rmdir($root);
        }
    }

    public function test_missing_top_level_view_keeps_typed_not_found(): void
    {
        $renderer = new NativePhpViewRenderer(new FilesystemViewPathResolver([new ViewSource(null, sys_get_temp_dir())]));
        $this->expectException(ViewNotFound::class);
        $renderer->render('missing-' . bin2hex(random_bytes(8)));
    }

    public function test_closed_render_buffer_does_not_consume_caller_buffer(): void
    {
        $root = sys_get_temp_dir() . '/evolve-view-' . bin2hex(random_bytes(8));
        mkdir($root);
        file_put_contents($root . '/close.php', '<?php ob_end_clean();');
        $before = ob_get_level();
        ob_start();
        echo 'caller output';
        $callerLevel = ob_get_level();
        try {
            $renderer = new NativePhpViewRenderer(new FilesystemViewPathResolver([new ViewSource(null, $root)]));
            try {
                $renderer->render('close');
                self::fail('Closing the render buffer must fail.');
            } catch (ViewRenderFailed $error) {
                self::assertSame($callerLevel, ob_get_level());
                self::assertSame('caller output', ob_get_contents());
            }
        } finally {
            while (ob_get_level() > $before) {
                ob_end_clean();
            }
            unlink($root . '/close.php');
            rmdir($root);
        }
    }

    public function test_cleanup_callback_cannot_replace_primary_template_throwable(): void
    {
        $root = sys_get_temp_dir() . '/evolve-view-' . bin2hex(random_bytes(8));
        mkdir($root);
        file_put_contents($root . '/throw.php', '<?php ob_start(static function (string $buffer): string { throw new \\RuntimeException("cleanup"); }); throw new \\LogicException("primary");');
        $level = ob_get_level();
        try {
            $renderer = new NativePhpViewRenderer(new FilesystemViewPathResolver([new ViewSource(null, $root)]));
            try {
                $renderer->render('throw');
                self::fail('Template must fail.');
            } catch (ViewRenderFailed $error) {
                self::assertInstanceOf(\LogicException::class, $error->getPrevious());
                self::assertSame('primary', $error->getPrevious()->getMessage());
                self::assertSame($level, ob_get_level());
            }
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            unlink($root . '/throw.php');
            rmdir($root);
        }
    }
}
