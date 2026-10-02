<?php

declare(strict_types=1);

namespace Evolve\View\Tests\Integration;

use Evolve\View\Exception\ViewRenderFailed;
use Evolve\View\FilesystemViewPathResolver;
use Evolve\View\Native\NativePhpViewRenderer;
use Evolve\View\ViewSource;
use PHPUnit\Framework\TestCase;

final class NativeViewRenderingIntegrationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/evolve-view-' . bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*.php') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
    }

    public function test_layout_partial_sections_and_shared_data(): void
    {
        $this->template('page', '<?php $view->layout("layout"); $view->section("title", $view->data("title")); echo $view->partial("item", ["name" => "<item>"]);');
        $this->template('item', '<?php echo $view->text($view->data("name"));');
        $this->template('layout', '<?php echo $view->sectionContent("title"), "|", $view->body();');
        $renderer = $this->renderer(['title' => '<shared>']);
        self::assertSame('&lt;shared&gt;|&lt;item&gt;', $renderer->render('page'));
        self::assertSame('&lt;override&gt;|&lt;item&gt;', $renderer->render('page', ['title' => '<override>']));
    }

    public function test_per_render_null_overrides_shared_value(): void
    {
        $this->template('nullable', '<?php echo $view->data("value", "fallback") === null ? "null" : $view->data("value", "fallback");');
        $renderer = $this->renderer(['value' => 'shared']);

        self::assertSame('shared', $renderer->render('nullable'));
        self::assertSame('null', $renderer->render('nullable', ['value' => null]));
        self::assertSame('shared', $renderer->render('nullable'));
    }

    public function test_failure_clears_nested_buffers_and_session_state(): void
    {
        $this->template('broken', '<?php $view->layout("layout"); $view->section("title", "leak"); echo $view->partial("fail");');
        $this->template('fail', '<?php ob_start(); throw new \\RuntimeException("boom");');
        $this->template('layout', '<?php echo $view->sectionContent("title"), $view->body();');
        $this->template('ok', '<?php echo $view->sectionContent("title"), $view->data("value", "clean");');
        $renderer = $this->renderer();
        $level = ob_get_level();
        try {
            $renderer->render('broken', ['value' => 'leak']);
            self::fail('Expected failure.');
        } catch (ViewRenderFailed $error) {
            self::assertInstanceOf(\RuntimeException::class, $error->getPrevious());
            self::assertSame($level, ob_get_level());
        }
        self::assertSame('clean', $renderer->render('ok'));
    }

    public function test_depth_limit_and_data_release(): void
    {
        $this->template('recursive', '<?php echo $view->partial("recursive");');
        $renderer = $this->renderer();
        try {
            $renderer->render('recursive');
            self::fail('Expected depth failure.');
        } catch (ViewRenderFailed $error) {
            self::assertSame('Maximum view nesting depth exceeded.', $error->getPrevious()?->getMessage());
        }
        $this->template('object', '<?php echo $view->data("object") instanceof \\stdClass ? "yes" : "no";');
        $object = new \stdClass();
        $reference = \WeakReference::create($object);
        self::assertSame('yes', $renderer->render('object', ['object' => $object]));
        unset($object);
        self::assertNull($reference->get());
    }

    public function test_depth_32_succeeds_and_data_is_not_extracted(): void
    {
        $this->template('bounded', '<?php echo "."; if ($view->data("depth") < 32) echo $view->partial("bounded", ["depth" => $view->data("depth") + 1]);');
        $this->template('locals', '<?php echo isset($secret) ? "leaked" : "isolated";');
        $renderer = $this->renderer();
        self::assertSame(str_repeat('.', 32), $renderer->render('bounded', ['depth' => 1]));
        self::assertSame('isolated', $renderer->render('locals', ['secret' => 'private']));
    }

    public function test_layout_failure_restores_buffers_and_next_render_is_clean(): void
    {
        $this->template('page', '<?php $view->layout("badlayout"); echo "body";');
        $this->template('badlayout', '<?php ob_start(); throw new \\RuntimeException("layout failed");');
        $this->template('ok', '<?php echo "ok";');
        $renderer = $this->renderer();
        $level = ob_get_level();
        try {
            $renderer->render('page');
            self::fail('Expected layout failure.');
        } catch (ViewRenderFailed $error) {
            self::assertSame('layout failed', $error->getPrevious()?->getMessage());
            self::assertSame($level, ob_get_level());
        }
        self::assertSame('ok', $renderer->render('ok'));
    }

    public function test_missing_partial_is_wrapped_as_execution_failure(): void
    {
        $this->template('page', '<?php echo $view->partial("missing");');
        try {
            $this->renderer()->render('page');
            self::fail('Expected missing partial.');
        } catch (ViewRenderFailed $error) {
            self::assertInstanceOf(\Evolve\View\Exception\ViewNotFound::class, $error->getPrevious());
        }
    }

    private function template(string $name, string $source): void
    {
        file_put_contents($this->root . '/' . $name . '.php', $source);
    }

    /** @param array<string, mixed> $shared */
    private function renderer(array $shared = []): NativePhpViewRenderer
    {
        return new NativePhpViewRenderer(new FilesystemViewPathResolver([new ViewSource(null, $this->root)]), $shared);
    }
}
