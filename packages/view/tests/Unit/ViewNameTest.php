<?php

declare(strict_types=1);

namespace Evolve\View\Tests\Unit;

use Evolve\View\Exception\InvalidViewName;
use Evolve\View\ViewName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ViewNameTest extends TestCase
{
    public function test_valid_names(): void
    {
        self::assertSame('home/index', (string) new ViewName('home/index'));
        self::assertSame('billing', (new ViewName('billing::invoice/show'))->namespace());
        self::assertSame('invoice/show', (new ViewName('billing::invoice/show'))->path());
    }

    #[DataProvider('invalidNames')]
    public function test_invalid_names_fail_before_lookup(string $name): void
    {
        $this->expectException(InvalidViewName::class);
        new ViewName($name);
    }

    /** @return list<array{string}> */
    public static function invalidNames(): array
    {
        return array_map(static fn(string $value): array => [$value], [
            '', ' home', 'home ', '/home', 'C:/home', '\\\\server\\home',
            'home\\index', "home\0index", 'home//index', '.', '..',
            'home/../index', 'home/./index', 'billing::', '::home',
            'billing:::home', 'billing::other::home', 'home.php',
            'Home/index', 'Billing::home',
        ]);
    }
}
