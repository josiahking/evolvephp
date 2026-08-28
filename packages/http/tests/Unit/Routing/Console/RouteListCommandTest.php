<?php

declare(strict_types=1);

namespace Evolve\Http\Tests\Unit\Routing\Console;

use Evolve\Core\Console\CommandInput;
use Evolve\Core\Console\CommandOutput;
use Evolve\Http\Routing\Console\RouteListCommand;
use Evolve\Http\Routing\Route;
use Evolve\Http\Routing\RouteCollection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RouteListCommandTest extends TestCase
{
    public function testCommandMetadataIsExact(): void
    {
        $command = $this->command([]);

        self::assertTrue((new \ReflectionClass(RouteListCommand::class))->isFinal());
        self::assertTrue((new \ReflectionClass(RouteListCommand::class))->isReadOnly());
        self::assertSame('route:list', $command->name());
        self::assertSame('List configured HTTP routes.', $command->description());
    }

    public function testEmptyCollectionEmitsMessageAndReturnsSuccess(): void
    {
        $output = new RecordingCommandOutput();
        $result = $this->command([])->execute(new CommandInput([]), $output);

        self::assertSame(0, $result->exitCode());
        self::assertSame(['No routes are configured.'], $output->normal);
        self::assertSame([], $output->error);
    }

    public function testRoutesAreRenderedWithExactMethodsPathsAndOrder(): void
    {
        $output = new RecordingCommandOutput();
        $firstHandler = $this->handler();
        $secondHandler = $this->handler();
        $thirdHandler = $this->handler();
        $command = $this->command([
            new Route(['GET'], '/users', $firstHandler),
            new Route(['post', 'PATCH'], '/mixed-case', $secondHandler),
            new Route(['DELETE'], '/users/{id}', $thirdHandler),
        ]);

        $result = $command->execute(new CommandInput([]), $output);

        self::assertSame(0, $result->exitCode());
        self::assertSame(
            [
                'GET /users',
                'post|PATCH /mixed-case',
                'DELETE /users/{id}',
            ],
            $output->normal,
        );
        self::assertSame([], $output->error);
        self::assertSame(0, $firstHandler->calls);
        self::assertSame(0, $secondHandler->calls);
        self::assertSame(0, $thirdHandler->calls);
    }

    public function testUnsupportedArgumentReturnsUsageErrorOnly(): void
    {
        $output = new RecordingCommandOutput();
        $handler = $this->handler();
        $result = $this->command([new Route(['GET'], '/users', $handler)])
            ->execute(new CommandInput(['--json']), $output);

        self::assertSame(2, $result->exitCode());
        self::assertSame([], $output->normal);
        self::assertSame(['The route:list command does not accept arguments or options.'], $output->error);
        self::assertSame(0, $handler->calls);
    }

    public function testMultipleUnsupportedTokensAreRejectedIdentically(): void
    {
        $output = new RecordingCommandOutput();
        $result = $this->command([new Route(['GET'], '/users', $this->handler())])
            ->execute(new CommandInput(['--verbose', '/users']), $output);

        self::assertSame(2, $result->exitCode());
        self::assertSame([], $output->normal);
        self::assertSame(['The route:list command does not accept arguments or options.'], $output->error);
    }

    /**
     * @param list<Route> $routes
     */
    private function command(array $routes): RouteListCommand
    {
        return new RouteListCommand(new RouteCollection($routes));
    }

    private function handler(): RecordingRouteListHandler
    {
        return new RecordingRouteListHandler($this->createStub(ResponseInterface::class));
    }
}

final class RecordingCommandOutput implements CommandOutput
{
    /**
     * @var list<string>
     */
    public array $normal = [];

    /**
     * @var list<string>
     */
    public array $error = [];

    public function write(string $message): void
    {
        $this->normal[] = $message;
    }

    public function writeError(string $message): void
    {
        $this->error[] = $message;
    }
}

final class RecordingRouteListHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    public function __construct(private readonly ResponseInterface $response) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->calls++;

        return $this->response;
    }
}
