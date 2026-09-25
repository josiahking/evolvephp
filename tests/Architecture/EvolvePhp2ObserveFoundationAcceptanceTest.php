<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EvolvePhp2ObserveFoundationAcceptanceTest extends TestCase
{
    private const DESCRIPTION = 'OpenTelemetry composition, execution and HTTP server tracing, metrics, log correlation and bounded export-processing foundation for EvolvePHP 2.';

    public function testPublishedObservePackageStaysOptionalAndClosedToAcceptedBoundaries(): void
    {
        $manifest = json_decode(
            file_get_contents(__DIR__ . '/../../packages/observe/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('evolvephp/observe', $manifest['name']);
        $this->assertSame(self::DESCRIPTION, $manifest['description']);
        $this->assertSame(['php' => '^8.4', 'evolvephp/core' => '^2.0', 'evolvephp/http' => '^2.0', 'open-telemetry/api' => '^1.10', 'open-telemetry/sem-conv' => '^1.44', 'psr/http-message' => '^1.1 || ^2.0', 'psr/http-server-handler' => '^1.0', 'psr/http-server-middleware' => '^1.0'], $manifest['require']);
        $this->assertArrayHasKey('open-telemetry/sdk', $manifest['suggest']);
        $this->assertArrayNotHasKey('open-telemetry/sdk', $manifest['require']);
        $this->assertArrayNotHasKey('open-telemetry/exporter-otlp', $manifest['require']);
        $this->assertArrayNotHasKey('open-telemetry/exporter-otlp', $manifest['suggest']);
        $this->assertArrayNotHasKey('evolvephp/insight', $manifest['require']);
        $this->assertArrayNotHasKey('evolvephp/bridge-remote', $manifest['require']);

        $readme = file_get_contents(__DIR__ . '/../../packages/observe/README.md');
        $this->assertStringContainsString('bounded metrics', $readme);
        $this->assertStringContainsString('structured-log correlation', $readme);
        $this->assertStringContainsString('application-owned', $readme);
        $this->assertStringContainsString('SDK remains suggested rather than required', $readme);
        $this->assertStringContainsString('OTLP exporters and transports remain application-owned', $readme);
        $this->assertStringContainsString('Applications own OpenTelemetry setup', $readme);
        $this->assertStringContainsString('request bodies, response bodies, authorization, cookies', $readme);
        $this->assertStringContainsString('user identity, tenant identity, session identity', $readme);
        $this->assertStringContainsString('exception messages, stack traces', $readme);
        $this->assertStringContainsString('Inbound baggage is deny-by-default', $readme);
        $this->assertStringContainsString('database metrics, cache metrics, storage metrics', $readme);
        $this->assertStringContainsString('outbound HTTP-client spans or metrics', $readme);
        $this->assertStringNotContainsString('Phase 9.9', $readme);
    }
}
