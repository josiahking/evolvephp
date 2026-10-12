<?php

declare(strict_types=1);

namespace Evolve\Mcp\Client;

use Closure;
use Evolve\Secret\Contracts\SecretName;
use InvalidArgumentException;
use Mcp\Schema\Enum\ProtocolVersion;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** Explicit, immutable policy and limits for one MCP client connection. */
final readonly class McpClientConfiguration
{
    /** @var Closure(string): bool|null */
    public ?Closure $approveDestination;

    /** @var Closure(string, array<string>, string, array<string, string>): bool|null */
    public ?Closure $approveCommand;

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     * @param array<string, SecretName> $secretEnvironment
     */
    private function __construct(
        public string $mode,
        public ProtocolVersion $protocolVersion,
        public int $initTimeoutSeconds,
        public int $requestTimeoutSeconds,
        public int $maxPages,
        public int $maxItems,
        public int $maxResponseBytes,
        public int $retries,
        public ?string $endpoint,
        public ?ClientInterface $httpClient,
        public ?RequestFactoryInterface $requestFactory,
        public ?StreamFactoryInterface $streamFactory,
        ?Closure $approveDestination,
        public ?SecretName $bearerSecret,
        public ?string $command,
        public array $arguments,
        public ?string $workingDirectory,
        public array $environment,
        public array $secretEnvironment,
        ?Closure $approveCommand,
    ) {
        $this->approveDestination = $approveDestination;
        $this->approveCommand = $approveCommand;
        if ($initTimeoutSeconds < 1 || $initTimeoutSeconds > 300
            || $requestTimeoutSeconds < 1 || $requestTimeoutSeconds > 300
            || $maxPages < 1 || $maxPages > 100
            || $maxItems < 1 || $maxItems > 10000
            || $maxResponseBytes < 1 || $maxResponseBytes > 16777216
            || $retries !== 0) {
            throw new InvalidArgumentException('MCP client limits or retry policy are invalid.');
        }
    }

    public static function http(
        string $endpoint,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory,
        callable $approveDestination,
        ?SecretName $bearerSecret = null,
        int $initTimeoutSeconds = 10,
        int $requestTimeoutSeconds = 30,
        int $maxPages = 5,
        int $maxItems = 100,
        int $maxResponseBytes = 1048576,
    ): self {
        $parts = parse_url($endpoint);
        if (!is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['fragment'])
            || isset($parts['query'])
            || preg_match('/[\x00-\x1f\x7f]/', $endpoint) === 1) {
            throw new InvalidArgumentException('MCP HTTP endpoint is invalid or unapproved.');
        }
        try {
            $approved = ($approveDestination)($endpoint) === true;
        } catch (\Throwable) {
            $approved = false;
        }
        if (!$approved) {
            throw new InvalidArgumentException('MCP HTTP endpoint is invalid or unapproved.');
        }

        return new self(
            'http',
            ProtocolVersion::V2026_07_28,
            $initTimeoutSeconds,
            $requestTimeoutSeconds,
            $maxPages,
            $maxItems,
            $maxResponseBytes,
            0,
            $endpoint,
            $httpClient,
            $requestFactory,
            $streamFactory,
            Closure::fromCallable($approveDestination),
            $bearerSecret,
            null,
            [],
            null,
            [],
            [],
            null,
        );
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     * @param array<string, SecretName> $secretEnvironment
     */
    public static function stdio(
        string $command,
        array $arguments,
        string $workingDirectory,
        array $environment,
        callable $approveCommand,
        array $secretEnvironment = [],
        int $initTimeoutSeconds = 10,
        int $requestTimeoutSeconds = 30,
        int $maxPages = 5,
        int $maxItems = 100,
    ): self {
        if (!self::isAbsolute($command)
            || !is_file($command)
            || !self::isAbsolute($workingDirectory)
            || !is_dir($workingDirectory)

            || count($arguments) > 64) {
            throw new InvalidArgumentException('MCP STDIO command is invalid or unapproved.');
        }
        foreach ($arguments as $argument) {
            if (str_contains($argument, "\0") || strlen($argument) > 4096) {
                throw new InvalidArgumentException('MCP STDIO argument is invalid.');
            }
        }
        foreach ($environment as $name => $value) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1 || str_contains($value, "\0")) {
                throw new InvalidArgumentException('MCP STDIO environment is invalid.');
            }
        }
        foreach ($secretEnvironment as $name => $value) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
                throw new InvalidArgumentException('MCP STDIO secret environment is invalid.');
            }
        }
        if (array_intersect_key($environment, $secretEnvironment) !== []) {
            throw new InvalidArgumentException('MCP STDIO environment and secret names overlap.');
        }

        try {
            $approved = ($approveCommand)($command, $arguments, $workingDirectory, $environment) === true;
        } catch (\Throwable) {
            $approved = false;
        }
        if (!$approved) {
            throw new InvalidArgumentException('MCP STDIO command is invalid or unapproved.');
        }
        return new self(
            'stdio',
            ProtocolVersion::V2025_06_18,
            $initTimeoutSeconds,
            $requestTimeoutSeconds,
            $maxPages,
            $maxItems,
            1048576,
            0,
            null,
            null,
            null,
            null,
            null,
            null,
            $command,
            $arguments,
            $workingDirectory,
            $environment,
            $secretEnvironment,
            Closure::fromCallable($approveCommand),
        );
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
    }
}
