<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit\Capture;

use Evolve\Insight\Capture\DefaultDiagnosticRedactor;
use Evolve\Insight\Capture\DiagnosticAttribute;
use Evolve\Insight\Capture\DiagnosticDataClassification;
use PHPUnit\Framework\TestCase;

final class DefaultDiagnosticRedactorTest extends TestCase
{
    public function testOrdinarySafeOperationalAttributePassesUnchanged(): void
    {
        $attribute = new DiagnosticAttribute('route_name', DiagnosticDataClassification::PublicOperationalMetadata, 'users.show');

        self::assertSame($attribute, (new DefaultDiagnosticRedactor())->redact($attribute));
    }

    public function testSecretAndAuthenticationClassificationsAreSuppressed(): void
    {
        $redactor = new DefaultDiagnosticRedactor();

        self::assertNull($redactor->redact(new DiagnosticAttribute('api_key', DiagnosticDataClassification::SecretData, 'secret')));
        self::assertNull($redactor->redact(new DiagnosticAttribute('authorization', DiagnosticDataClassification::AuthenticationData, 'Bearer token')));
    }

    public function testSensitiveMachineNamesDoNotRetainRawValues(): void
    {
        foreach ($this->sensitiveMachineNames() as $name) {
            $redacted = (new DefaultDiagnosticRedactor())->redact(
                new DiagnosticAttribute($name, DiagnosticDataClassification::PublicOperationalMetadata, 'raw-sensitive-value'),
            );

            self::assertInstanceOf(DiagnosticAttribute::class, $redacted);
            self::assertSame(DefaultDiagnosticRedactor::REDACTION_MARKER, $redacted->value());
            self::assertSame($name, $redacted->name());
        }
    }

    public function testSensitiveNameMatchingIsDeterministic(): void
    {
        $redactor = new DefaultDiagnosticRedactor();
        $attribute = new DiagnosticAttribute('request.access_token', DiagnosticDataClassification::InternalOperationalMetadata, 'token');

        self::assertEquals($redactor->redact($attribute), $redactor->redact($attribute));
    }

    public function testOrdinaryNamesAreNotBroadlyOverRedacted(): void
    {
        $attribute = new DiagnosticAttribute('passwordless_mode', DiagnosticDataClassification::PublicOperationalMetadata, true);
        $redacted = (new DefaultDiagnosticRedactor())->redact($attribute);

        self::assertSame($attribute, $redacted);
    }

    /**
     * @return list<string>
     */
    private function sensitiveMachineNames(): array
    {
        return array(
            'authorization',
            'authentication',
            'password',
            'passwd',
            'cookie',
            'set-cookie',
            'access_token',
            'refresh.token',
            'api-key',
            'secret',
            'session.id',
        );
    }
}
