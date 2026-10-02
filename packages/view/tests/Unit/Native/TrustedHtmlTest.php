<?php

declare(strict_types=1);

namespace Evolve\View\Tests\Unit\Native;

use Evolve\View\Native\TrustedHtml;
use PHPUnit\Framework\TestCase;

final class TrustedHtmlTest extends TestCase
{
    public function test_preserves_caller_supplied_markup(): void
    {
        self::assertSame('<b>trusted</b>', (new TrustedHtml('<b>trusted</b>'))->html);
    }
}
