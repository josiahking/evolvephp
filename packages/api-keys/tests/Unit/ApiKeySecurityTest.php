<?php

declare(strict_types=1);

namespace Evolve\ApiKeys\Tests\Unit;

use DateTimeImmutable;
use Evolve\ApiKeys\ApiKeyIssueRequest;
use Evolve\ApiKeys\ApiKeyRecord;
use Evolve\ApiKeys\ApiKeyVerificationRequest;
use Evolve\ApiKeys\IssuedApiKey;
use PHPUnit\Framework\TestCase;

final class ApiKeySecurityTest extends TestCase
{
    public function testSensitiveFieldsAreRedactedFromNormalSerialization(): void
    {
        $issued = new IssuedApiKey('public-id', 'secret-sentinel');
        self::assertSame('{"identifier":"public-id"}', json_encode($issued, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret-sentinel', print_r($issued, true));
        try {
            serialize($issued);
            self::fail('Expected serialization to be prohibited.');
        } catch (\LogicException) {
        }
        $record = new ApiKeyRecord('public-id', str_repeat('a', 64), 'user-1', 'tenant-a', ['read'], new DateTimeImmutable('2030-01-01'));
        self::assertStringNotContainsString(str_repeat('a', 64), json_encode($record, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(str_repeat('a', 64), print_r($record, true));
        try {
            serialize($record);
            self::fail('Expected serialization to be prohibited.');
        } catch (\LogicException) {
        }
    }

    public function testConstructorExceptionsRedactSensitiveArguments(): void
    {
        $original = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');
        try {
            self::assertSame('0', ini_get('zend.exception_ignore_args'));

            try {
                new ApiKeyVerificationRequest('credential-trace-sentinel', 'invalid principal', 'tenant-a', [], new DateTimeImmutable('2029-01-01'));
                self::fail('Expected invalid principal.');
            } catch (\InvalidArgumentException $failure) {
                $constructor = array_values(array_filter($failure->getTrace(), static fn(array $frame): bool => $frame['function'] === '__construct'))[0] ?? [];
                $arguments = $constructor['args'] ?? [];
                self::assertNotEmpty($arguments);
                self::assertInstanceOf(\SensitiveParameterValue::class, $arguments[0]);
                self::assertSame('credential-trace-sentinel', $arguments[0]->getValue());
            }

            try {
                new ApiKeyRecord('public-id', str_repeat('d', 64), 'invalid principal', 'tenant-a', ['read'], new DateTimeImmutable('2030-01-01'));
                self::fail('Expected invalid principal.');
            } catch (\InvalidArgumentException $failure) {
                $constructor = array_values(array_filter($failure->getTrace(), static fn(array $frame): bool => $frame['function'] === '__construct'))[0] ?? [];
                $arguments = $constructor['args'] ?? [];
                self::assertNotEmpty($arguments);
                self::assertInstanceOf(\SensitiveParameterValue::class, $arguments[1]);
                self::assertSame(str_repeat('d', 64), $arguments[1]->getValue());
            }
        } finally {
            ini_set('zend.exception_ignore_args', $original === false ? '1' : $original);
        }
    }

    public function testIssuedCredentialConstructorMarksSecretAsSensitive(): void
    {
        $constructor = new \ReflectionMethod(IssuedApiKey::class, '__construct');
        $credential = $constructor->getParameters()[1];
        self::assertCount(1, $credential->getAttributes(\SensitiveParameter::class));
    }
    public function testMalformedIdentifiersAndScopesAreRejected(): void
    {
        foreach (['', 'bad tenant', str_repeat('x', 129), '../path'] as $value) {
            try {
                new ApiKeyIssueRequest('admin', 'user-1', $value, ['read'], new DateTimeImmutable('2030-01-01'));
                self::fail('Expected invalid tenant.');
            } catch (\InvalidArgumentException) {
            }
        }
        foreach ([[], ['read', 'read'], ['bad scope']] as $scopes) {
            try {
                new ApiKeyIssueRequest('admin', 'user-1', 'tenant-a', $scopes, new DateTimeImmutable('2030-01-01'));
                self::fail('Expected invalid scopes.');
            } catch (\InvalidArgumentException) {
            }
        }
        $request = new ApiKeyVerificationRequest('malformed-secret-sentinel', 'user-1', 'tenant-a', [], new DateTimeImmutable('2029-01-01'));
        self::assertSame('malformed-secret-sentinel', $request->credential());
    }

    public function testVerificationRequestDoesNotExposeCredentialThroughSerialization(): void
    {
        $request = new ApiKeyVerificationRequest('secret-sentinel', 'user-1', 'tenant-a', [], new DateTimeImmutable('2029-01-01'));
        self::assertStringNotContainsString('secret-sentinel', json_encode($request, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret-sentinel', print_r($request, true));
        try {
            serialize($request);
            self::fail('Expected serialization to be prohibited.');
        } catch (\LogicException $failure) {
            self::assertStringNotContainsString('secret-sentinel', $failure->getMessage());
        }
    }
}
