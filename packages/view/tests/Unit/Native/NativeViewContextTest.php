<?php

declare(strict_types=1);

namespace Evolve\View\Tests\Unit\Native;

use Evolve\View\Native\Internal\RenderSession;
use Evolve\View\Native\NativeViewContext;
use Evolve\View\Native\TrustedHtml;
use Evolve\View\ViewPathResolver;
use PHPUnit\Framework\TestCase;

final class NativeViewContextTest extends TestCase
{
    public function test_default_escaping_and_trusted_html(): void
    {
        $resolver = $this->createStub(ViewPathResolver::class);
        $context = new NativeViewContext(new RenderSession($resolver), []);
        self::assertSame('&lt;&gt;&amp;&#039;&quot;', $context->text('<>&\'"'));
        self::assertSame("\u{FFFD}", $context->text("\xFF"));
        self::assertSame('<b>x</b>', $context->text(new TrustedHtml('<b>x</b>')));
        self::assertSame('<b>x</b>', $context->raw(new TrustedHtml('<b>x</b>')));
        $context->section('safe', '<b>x</b>');
        $context->section('trusted', new TrustedHtml('<b>x</b>'));
        self::assertSame('&lt;b&gt;x&lt;/b&gt;', $context->sectionContent('safe'));
        self::assertSame('<b>x</b>', $context->sectionContent('trusted'));
    }

    public function test_data_preserves_explicit_null_and_uses_default_only_for_missing_key(): void
    {
        $resolver = $this->createStub(ViewPathResolver::class);
        $context = new NativeViewContext(new RenderSession($resolver), ['value' => null]);

        self::assertNull($context->data('value', 'fallback'));
        self::assertSame('fallback', $context->data('missing', 'fallback'));
    }
}
