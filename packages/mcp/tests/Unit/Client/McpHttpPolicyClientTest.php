<?php

declare(strict_types=1);

namespace Evolve\Mcp\Tests\Unit\Client;

use Evolve\Mcp\Client\McpHttpPolicyClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class McpHttpPolicyClientTest extends TestCase
{
    public function test_json_only_accept_and_bounded_json_response(): void
    {
        $factory = new HttpFactory();
        $inner = $this->createMock(ClientInterface::class);
        $inner->expects(self::once())->method('sendRequest')->willReturnCallback(
            static function (RequestInterface $request) use ($factory): ResponseInterface {
                self::assertSame('application/json', $request->getHeaderLine('Accept'));
                return $factory->createResponse(200)
                    ->withHeader('Content-Type', 'application/json')
                    ->withBody($factory->createStream('{"jsonrpc":"2.0","id":1,"result":{}}'));
            },
        );
        $policy = new McpHttpPolicyClient($inner, $factory, 'https://localhost/mcp', static fn(): bool => true, 128);
        $response = $policy->sendRequest($factory->createRequest('POST', 'https://localhost/mcp'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"jsonrpc":"2.0","id":1,"result":{}}', (string) $response->getBody());
    }

    public function test_sse_session_and_oversized_responses_are_refused(): void
    {
        $factory = new HttpFactory();
        foreach ([
            $factory->createResponse(200)->withHeader('Content-Type', 'text/event-stream'),
            $factory->createResponse(200)->withHeader('Content-Type', 'text/plain'),
            $factory->createResponse(200)->withHeader('Content-Type', 'application/json')->withHeader('Mcp-Session-Id', 'session'),
            $factory->createResponse(302)->withHeader('Location', 'https://elsewhere.example/'),
            $factory->createResponse(200)->withHeader('Content-Type', 'application/json')->withBody($factory->createStream(str_repeat('x', 129))),
        ] as $response) {
            $inner = $this->createStub(ClientInterface::class);
            $inner->method('sendRequest')->willReturn($response);
            $policy = new McpHttpPolicyClient($inner, $factory, 'https://localhost/mcp', static fn(): bool => true, 128);
            try {
                $policy->sendRequest($factory->createRequest('POST', 'https://localhost/mcp'));
                self::fail('Unsafe response accepted.');
            } catch (\Evolve\Mcp\Client\McpClientException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_unknown_size_and_stalled_body_are_refused_without_trusting_length(): void
    {
        $factory = new HttpFactory();
        $body = $this->createStub(\Psr\Http\Message\StreamInterface::class);
        $body->method('getSize')->willReturn(null);
        $body->method('eof')->willReturn(false);
        $body->method('read')->willReturn('');
        $inner = $this->createStub(ClientInterface::class);
        $inner->method('sendRequest')->willReturn(
            $factory->createResponse(200)->withHeader('Content-Type', 'application/json')->withBody($body),
        );
        $policy = new McpHttpPolicyClient($inner, $factory, 'https://localhost/mcp', static fn(): bool => true, 128);

        $this->expectException(\Evolve\Mcp\Client\McpClientException::class);
        $policy->sendRequest($factory->createRequest('POST', 'https://localhost/mcp'));
    }

    public function test_destination_callback_failure_is_redacted_before_io(): void
    {
        $factory = new HttpFactory();
        $inner = $this->createMock(ClientInterface::class);
        $inner->expects(self::never())->method('sendRequest');
        $policy = new McpHttpPolicyClient(
            $inner,
            $factory,
            'https://localhost/mcp',
            static function (): never {
                throw new \RuntimeException('private destination detail');
            },
            128,
        );

        try {
            $policy->sendRequest($factory->createRequest('POST', 'https://localhost/mcp'));
            self::fail('Expected safe destination-policy error.');
        } catch (\Evolve\Mcp\Client\McpClientException $exception) {
            self::assertStringNotContainsString('private', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }
    public function test_destination_policy_denial_prevents_request_and_redacts_transport_error(): void
    {
        $factory = new HttpFactory();
        $inner = $this->createMock(ClientInterface::class);
        $inner->expects(self::never())->method('sendRequest');
        $policy = new McpHttpPolicyClient($inner, $factory, 'https://localhost/mcp', static fn(): bool => false, 128);
        $this->expectException(\Evolve\Mcp\Client\McpClientException::class);
        $policy->sendRequest($factory->createRequest('POST', 'https://localhost/mcp'));
    }

    public function test_transfer_encoding_is_rejected_before_transport_io_without_secret_disclosure(): void
    {
        $factory = new HttpFactory();
        $inner = $this->createMock(ClientInterface::class);
        $inner->expects(self::never())->method('sendRequest');
        $policy = new McpHttpPolicyClient(
            $inner,
            $factory,
            'https://localhost/mcp',
            static fn(): bool => true,
            128,
            'private-bearer-secret',
        );
        $request = $factory->createRequest('POST', 'https://localhost/mcp')
            ->withHeader('Transfer-Encoding', 'chunked')
            ->withBody($factory->createStream('private-body-payload'));

        try {
            $policy->sendRequest($request);
            self::fail('Transfer-Encoding request was forwarded.');
        } catch (\Evolve\Mcp\Client\McpClientException $exception) {
            self::assertSame(\Evolve\Mcp\Client\McpClientFailureCategory::Security, $exception->category);
            self::assertStringNotContainsString('private-body-payload', $exception->getMessage());
            self::assertStringNotContainsString('private-bearer-secret', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function test_accepted_request_uses_buffered_content_length_without_transfer_encoding(): void
    {
        $factory = new HttpFactory();
        $inner = $this->createMock(ClientInterface::class);
        $inner->expects(self::once())->method('sendRequest')->willReturnCallback(
            static function (RequestInterface $request) use ($factory): ResponseInterface {
                self::assertSame('5', $request->getHeaderLine('Content-Length'));
                self::assertFalse($request->hasHeader('Transfer-Encoding'));
                self::assertSame('hello', (string) $request->getBody());
                return $factory->createResponse(202);
            },
        );
        $policy = new McpHttpPolicyClient($inner, $factory, 'https://localhost/mcp', static fn(): bool => true, 128);
        $request = $factory->createRequest('POST', 'https://localhost/mcp')
            ->withHeader('Content-Length', '999')
            ->withBody($factory->createStream('hello'));

        self::assertSame(202, $policy->sendRequest($request)->getStatusCode());
    }
    public function test_outbound_request_body_limit_rejects_before_transport_io(): void
    {
        $factory = new HttpFactory();
        $inner = $this->createMock(ClientInterface::class);
        $inner->expects(self::never())->method('sendRequest');
        $policy = new McpHttpPolicyClient(
            $inner,
            $factory,
            'https://localhost/mcp',
            static fn(): bool => true,
            128,
            maxRequestBytes: 4,
        );

        $this->expectException(\Evolve\Mcp\Client\McpClientException::class);
        $policy->sendRequest($factory->createRequest('POST', 'https://localhost/mcp')->withBody($factory->createStream('12345')));
    }
}
