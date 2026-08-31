<?php

declare(strict_types=1);

namespace Benchmark\Laravel;

use Closure;
use Illuminate\Http\Request;

final class MiddlewareState
{
    /** @var list<int> */
    public static array $order = [];
}

abstract class OrderedMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        MiddlewareState::$order[] = $this->index();

        return $next($request);
    }

    abstract protected function index(): int;
}

final class Middleware1 extends OrderedMiddleware
{
    protected function index(): int
    {
        return 1;
    }
}

final class Middleware2 extends OrderedMiddleware
{
    protected function index(): int
    {
        return 2;
    }
}

final class Middleware3 extends OrderedMiddleware
{
    protected function index(): int
    {
        return 3;
    }
}

final class Middleware4 extends OrderedMiddleware
{
    protected function index(): int
    {
        return 4;
    }
}

final class Middleware5 extends OrderedMiddleware
{
    protected function index(): int
    {
        return 5;
    }
}
