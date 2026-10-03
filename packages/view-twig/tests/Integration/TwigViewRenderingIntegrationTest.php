<?php

declare(strict_types=1);

namespace Evolve\View\Twig\Tests\Integration;

use Evolve\View\Exception\ViewRenderFailed;
use Evolve\View\Twig\TwigViewPathResolver;
use Evolve\View\Twig\TwigViewRenderer;
use Evolve\View\ViewSource;
use PHPUnit\Framework\TestCase;

final class TwigViewRenderingIntegrationTest extends TestCase
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

    public function test_plain_namespaced_include_layout_escaping_and_raw(): void
    {
        $global = $this->root();
        $billing = $this->root();
        $this->template($global, 'layout', '<main>{% block body %}{% endblock %}</main>');
        $this->template($global, 'page', "{% extends 'layout' %}{% block body %}{% include 'billing::item' %}|{{ value }}|{{ value|raw }}{% endblock %}");
        $this->template($billing, 'item', 'item');
        $renderer = new TwigViewRenderer(new TwigViewPathResolver([
            new ViewSource(null, $global),
            new ViewSource('billing', $billing),
        ]), ['value' => '<b>']);
        self::assertSame('<main>item|&lt;b&gt;|<b></main>', $renderer->render('page'));
        self::assertSame('<main>item|&lt;i&gt;|<i></main>', $renderer->render('page', ['value' => '<i>']));
    }

    public function test_failed_render_does_not_poison_next_render_or_retain_data(): void
    {
        $root = $this->root();
        $this->template($root, 'broken', '{{ missing_function() }}');
        $this->template($root, 'ok', '{{ object.value }}');
        $renderer = new TwigViewRenderer(new TwigViewPathResolver([new ViewSource(null, $root)]));
        try {
            $renderer->render('broken');
            self::fail('Expected Twig failure.');
        } catch (ViewRenderFailed $error) {
            self::assertInstanceOf(\Twig\Error\SyntaxError::class, $error->getPrevious());
        }
        $object = (object) ['value' => 'clean'];
        $reference = \WeakReference::create($object);
        self::assertSame('clean', $renderer->render('ok', ['object' => $object]));
        unset($object);
        self::assertNull($reference->get());
    }

    public function test_failed_render_releases_object_supplied_only_as_render_data(): void
    {
        $root = $this->root();
        $this->template($root, 'broken', '{{ missing_function(object) }}');
        $renderer = new TwigViewRenderer(new TwigViewPathResolver([new ViewSource(null, $root)]));
        $object = new \stdClass();
        $reference = \WeakReference::create($object);

        try {
            $renderer->render('broken', ['object' => $object]);
            self::fail('Expected Twig failure.');
        } catch (ViewRenderFailed $error) {
            self::assertNotNull($error->getPrevious());
        }

        unset($error, $object);
        self::assertNull($reference->get());
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/evolve-twig-' . bin2hex(random_bytes(8));
        mkdir($root);
        $this->roots[] = $root;
        return $root;
    }

    private function template(string $root, string $name, string $source): void
    {
        file_put_contents($root . '/' . $name . '.html.twig', $source);
    }
}
