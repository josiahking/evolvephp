<?php

declare(strict_types=1);

namespace Evolve\Bridge\Symfony\Tests\Unit;

use Evolve\Bridge\Symfony\SymfonyBridgeContextFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class SymfonyBridgeContextFactoryTest extends TestCase
{
    public function test_anonymous_context_maps_explicit_host_inputs_without_hidden_generation(): void
    {
        $context = (new SymfonyBridgeContextFactory(new SymfonyBridgeTokenStorage(null)))->create(
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

    public function test_token_without_user_maps_no_principal(): void
    {
        $context = (new SymfonyBridgeContextFactory(new SymfonyBridgeTokenStorage(new SymfonyBridgeToken(null))))->create(
            'request-1',
            'correlation-1',
        );

        self::assertNull($context->principalIdentifier());
    }

    public function test_authenticated_principal_maps_only_safe_user_identifier(): void
    {
        $principal = new SymfonyBridgeUser('user-42');
        $context = (new SymfonyBridgeContextFactory(new SymfonyBridgeTokenStorage(new SymfonyBridgeToken($principal))))->create(
            'request-1',
            'correlation-1',
        );

        self::assertSame('user-42', $context->principalIdentifier());
        self::assertNotSame($principal, $context->principalIdentifier());
    }
}

final class SymfonyBridgeTokenStorage implements TokenStorageInterface
{
    public function __construct(private ?TokenInterface $token) {}

    public function getToken(): ?TokenInterface
    {
        return $this->token;
    }

    public function setToken(?TokenInterface $token = null): void
    {
        $this->token = $token;
    }
}

final class SymfonyBridgeToken implements TokenInterface
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private ?UserInterface $user,
        private array $attributes = [],
    ) {}

    public function __toString(): string
    {
        return 'symfony-bridge-token';
    }

    public function getUserIdentifier(): string
    {
        return $this->user?->getUserIdentifier() ?? '';
    }

    /**
     * @return list<string>
     */
    public function getRoleNames(): array
    {
        return [];
    }

    public function getUser(): ?UserInterface
    {
        return $this->user;
    }

    public function setUser(UserInterface $user): void
    {
        $this->user = $user;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function setAttributes(array $attributes): void
    {
        $this->attributes = $attributes;
    }

    public function hasAttribute(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    public function getAttribute(string $name): mixed
    {
        if (! $this->hasAttribute($name)) {
            throw new \InvalidArgumentException('Attribute not available.');
        }

        return $this->attributes[$name];
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    /**
     * @return array{user: UserInterface|null, attributes: array<string, mixed>}
     */
    public function __serialize(): array
    {
        return [
            'user' => $this->user,
            'attributes' => $this->attributes,
        ];
    }

    /**
     * @param array{user?: UserInterface|null, attributes?: array<string, mixed>} $data
     */
    public function __unserialize(array $data): void
    {
        $this->user = $data['user'] ?? null;
        $this->attributes = $data['attributes'] ?? [];
    }
}

final readonly class SymfonyBridgeUser implements UserInterface
{
    public function __construct(private string $identifier) {}

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return [];
    }

    public function eraseCredentials(): void {}

    public function getUserIdentifier(): string
    {
        return $this->identifier;
    }
}
