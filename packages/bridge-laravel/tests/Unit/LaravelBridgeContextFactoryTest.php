<?php

declare(strict_types=1);

namespace Evolve\Bridge\Laravel\Tests\Unit;

use Evolve\Bridge\Laravel\LaravelBridgeContextFactory;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LaravelBridgeContextFactoryTest extends TestCase
{
    public function test_anonymous_request_maps_explicit_host_inputs_without_hidden_generation(): void
    {
        $request = Request::create('/delegated', 'GET');
        $request->setUserResolver(static fn(): null => null);

        $context = (new LaravelBridgeContextFactory())->create(
            $request,
            'request-123',
            'correlation-456',
            'tenant-789',
            'en_GB',
            'UTC',
        );

        self::assertSame('request-123', $context->requestIdentifier());
        self::assertSame('correlation-456', $context->correlationIdentifier());
        self::assertNull($context->principalIdentifier());
        self::assertSame('tenant-789', $context->tenantIdentifier());
        self::assertSame('en_GB', $context->locale());
        self::assertSame('UTC', $context->timezone());
    }

    public function test_authenticated_principal_maps_only_safe_scalar_identifier(): void
    {
        $principal = new LaravelAuthenticatablePrincipal(42);
        $request = Request::create('/delegated', 'GET');
        $request->setUserResolver(static fn(): LaravelAuthenticatablePrincipal => $principal);

        $context = (new LaravelBridgeContextFactory())->create($request, 'request-1', 'correlation-1');

        self::assertSame('42', $context->principalIdentifier());
        self::assertNotSame($principal, $context->principalIdentifier());
    }

    public function test_unsupported_authenticated_principal_type_fails_safely(): void
    {
        $request = Request::create('/delegated', 'GET');
        $request->setUserResolver(static fn(): object => new \stdClass());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Laravel authenticated principal must implement Illuminate\Contracts\Auth\Authenticatable.');

        (new LaravelBridgeContextFactory())->create($request, 'request-1', 'correlation-1');
    }

    public function test_unsupported_auth_identifier_value_fails_safely(): void
    {
        $request = Request::create('/delegated', 'GET');
        $request->setUserResolver(static fn(): LaravelAuthenticatablePrincipal => new LaravelAuthenticatablePrincipal(['not-safe']));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Laravel authenticated principal identifier must be a safe scalar value.');

        (new LaravelBridgeContextFactory())->create($request, 'request-1', 'correlation-1');
    }
}

final class LaravelAuthenticatablePrincipal implements Authenticatable
{
    public function __construct(private mixed $identifier) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->identifier;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }
}
