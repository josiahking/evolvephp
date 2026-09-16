<?php

declare(strict_types=1);

namespace Evolve\Insight\Capture;

final class DefaultDiagnosticRedactor implements DiagnosticRedactor
{
    public const string REDACTION_MARKER = '[REDACTED]';

    public function redact(DiagnosticAttribute $attribute): ?DiagnosticAttribute
    {
        if (
            $attribute->classification() === DiagnosticDataClassification::SecretData
            || $attribute->classification() === DiagnosticDataClassification::AuthenticationData
        ) {
            return null;
        }

        if ($this->isOperational($attribute) && $this->isSensitiveMachineName($attribute->name())) {
            return new DiagnosticAttribute(
                $attribute->name(),
                $attribute->classification(),
                self::REDACTION_MARKER,
            );
        }

        return $attribute;
    }

    private function isOperational(DiagnosticAttribute $attribute): bool
    {
        return $attribute->classification() === DiagnosticDataClassification::PublicOperationalMetadata
            || $attribute->classification() === DiagnosticDataClassification::InternalOperationalMetadata;
    }

    private function isSensitiveMachineName(string $name): bool
    {
        $segments = array_values(array_filter(
            preg_split('/[._\\-\\s\\/]+/', strtolower($name)) ?: array(),
            static fn (string $segment): bool => $segment !== '',
        ));

        foreach ($segments as $segment) {
            if (in_array($segment, array('authorization', 'authentication', 'password', 'passwd', 'cookie', 'secret', 'session'), true)) {
                return true;
            }
        }

        $pairs = array(
            array('set', 'cookie'),
            array('access', 'token'),
            array('refresh', 'token'),
            array('api', 'key'),
            array('session', 'id'),
            array('session', 'identifier'),
        );

        foreach ($pairs as $pair) {
            for ($index = 0, $count = count($segments) - 1; $index < $count; $index++) {
                if ($segments[$index] === $pair[0] && $segments[$index + 1] === $pair[1]) {
                    return true;
                }
            }
        }

        return false;
    }
}
