<?php

declare(strict_types=1);

namespace Evolve\Testing\Tests\Unit\View;

use Evolve\Testing\View\RecordingViewRenderer;
use PHPUnit\Framework\TestCase;

final class RecordingViewRendererTest extends TestCase
{
    public function test_records_in_order_and_resets(): void
    {
        $object = new \stdClass();
        $renderer = new RecordingViewRenderer();
        self::assertSame('', $renderer->render('first', ['object' => $object]));
        $renderer->render('second');
        self::assertSame('first', $renderer->renders()[0]->view);
        self::assertSame($object, $renderer->renders()[0]->data['object']);
        self::assertSame('second', $renderer->renders()[1]->view);
        $renderer->reset();
        self::assertSame([], $renderer->renders());
    }
}
