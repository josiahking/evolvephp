<?php

declare(strict_types=1);

namespace Evolve\Insight\Tests\Unit\Capture;

use Evolve\Insight\Capture\DefaultDiagnosticRedactor;
use Evolve\Insight\Capture\DeterministicDiagnosticSampler;
use Evolve\Insight\Capture\DiagnosticAttribute;
use Evolve\Insight\Capture\DiagnosticCaptureFilter;
use Evolve\Insight\Capture\DiagnosticCapturePolicy;
use Evolve\Insight\Capture\DiagnosticDataClassification;
use Evolve\Insight\Capture\DiagnosticEntry;
use Evolve\Insight\Capture\DiagnosticRedactor;
use PHPUnit\Framework\TestCase;

final class DiagnosticCapturePolicyTest extends TestCase
{
    public function testEmptyExecutionIdentifierCategoryAndNameAreRejected(): void
    {
        foreach (
            array(
                array('', 'http', 'request'),
                array('execution-1', '', 'request'),
                array('execution-1', 'http', ''),
            ) as $arguments
        ) {
            try {
                new DiagnosticEntry($arguments[0], $arguments[1], $arguments[2], array());
                self::fail('Expected empty diagnostic entry field to be rejected.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testPrimitiveAttributeTypesAreAccepted(): void
    {
        $entry = new DiagnosticEntry(
            'execution-1',
            'http',
            'request',
            array(
                new DiagnosticAttribute('string', DiagnosticDataClassification::PublicOperationalMetadata, 'value'),
                new DiagnosticAttribute('int', DiagnosticDataClassification::PublicOperationalMetadata, 1),
                new DiagnosticAttribute('float', DiagnosticDataClassification::PublicOperationalMetadata, 1.25),
                new DiagnosticAttribute('bool', DiagnosticDataClassification::PublicOperationalMetadata, true),
                new DiagnosticAttribute('null', DiagnosticDataClassification::PublicOperationalMetadata, null),
            ),
        );

        self::assertCount(5, $entry->attributes());
    }

    public function testNestedArraysAndObjectsCannotBeSuppliedThroughAttributeApi(): void
    {
        try {
            new DiagnosticAttribute('payload', DiagnosticDataClassification::PublicOperationalMetadata, $this->nonPrimitiveValue('array'));
            self::fail('Expected array attribute value to be rejected.');
        } catch (\TypeError) {
            self::addToAssertionCount(1);
        }

        try {
            new DiagnosticAttribute('payload', DiagnosticDataClassification::PublicOperationalMetadata, $this->nonPrimitiveValue('object'));
            self::fail('Expected object attribute value to be rejected.');
        } catch (\TypeError) {
            self::addToAssertionCount(1);
        }
    }

    public function testEmptyAndOversizedNamesOrStringValuesAreRejected(): void
    {
        foreach (
            array(
                static fn (): DiagnosticAttribute => new DiagnosticAttribute('', DiagnosticDataClassification::PublicOperationalMetadata, 'value'),
                static fn (): DiagnosticAttribute => new DiagnosticAttribute(str_repeat('a', DiagnosticAttribute::MAX_NAME_LENGTH + 1), DiagnosticDataClassification::PublicOperationalMetadata, 'value'),
                static fn (): DiagnosticAttribute => new DiagnosticAttribute('payload', DiagnosticDataClassification::PublicOperationalMetadata, str_repeat('a', DiagnosticAttribute::MAX_STRING_VALUE_LENGTH + 1)),
            ) as $factory
        ) {
            try {
                $factory();
                self::fail('Expected invalid diagnostic attribute bounds to be rejected.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testNonFiniteFloatsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DiagnosticAttribute('duration', DiagnosticDataClassification::PublicOperationalMetadata, INF);
    }

    public function testOnlyOperationalClassificationsAreAcceptedByDefault(): void
    {
        $accepted = $this->defaultPolicy()->apply(new DiagnosticEntry(
            'execution-1',
            'http',
            'request',
            array(
                new DiagnosticAttribute('route', DiagnosticDataClassification::PublicOperationalMetadata, 'users.show'),
                new DiagnosticAttribute('worker', DiagnosticDataClassification::InternalOperationalMetadata, 'worker-1'),
                new DiagnosticAttribute('email', DiagnosticDataClassification::PersonalData, 'person@example.com'),
                new DiagnosticAttribute('invoice', DiagnosticDataClassification::BusinessSensitivePayload, 'invoice-1'),
                new DiagnosticAttribute('diagnosis', DiagnosticDataClassification::RegulatedData, 'regulated'),
            ),
        ));

        self::assertNotNull($accepted);
        self::assertSame(array('route', 'worker'), $this->attributeNames($accepted));
    }

    public function testSecretAndAuthenticationValuesAreNeverAcceptedRaw(): void
    {
        $accepted = $this->defaultPolicy()->apply(new DiagnosticEntry(
            'execution-1',
            'http',
            'request',
            array(
                new DiagnosticAttribute('api_key', DiagnosticDataClassification::SecretData, 'secret-value'),
                new DiagnosticAttribute('authorization', DiagnosticDataClassification::AuthenticationData, 'Bearer token'),
                new DiagnosticAttribute('route', DiagnosticDataClassification::PublicOperationalMetadata, 'users.show'),
            ),
        ));

        self::assertNotNull($accepted);
        self::assertSame(array('route'), $this->attributeNames($accepted));
        self::assertSame('users.show', $accepted->attributes()[0]->value());
    }

    public function testCustomRedactorCannotBypassClassificationPolicyByReclassifyingAllowedAttributes(): void
    {
        $policy = new DiagnosticCapturePolicy(redactor: new ReclassifyingRedactor());

        $accepted = $policy->apply(new DiagnosticEntry(
            'execution-1',
            'http',
            'request',
            array(
                new DiagnosticAttribute('secret', DiagnosticDataClassification::PublicOperationalMetadata, 'candidate-secret'),
                new DiagnosticAttribute('authentication', DiagnosticDataClassification::PublicOperationalMetadata, 'candidate-authentication'),
                new DiagnosticAttribute('personal', DiagnosticDataClassification::PublicOperationalMetadata, 'candidate-personal'),
                new DiagnosticAttribute('route', DiagnosticDataClassification::PublicOperationalMetadata, 'users.show'),
            ),
        ));

        self::assertNotNull($accepted);
        self::assertSame(array('route'), $this->attributeNames($accepted));
        self::assertSame('users.show', $accepted->attributes()[0]->value());
        self::assertNotContains('returned-secret', $this->attributeValues($accepted));
        self::assertNotContains('returned-authentication', $this->attributeValues($accepted));
        self::assertNotContains('returned-personal', $this->attributeValues($accepted));
    }

    public function testAcceptedAttributeOrderIsPreservedCountIsBoundedAndDuplicateNamesUseFirstAcceptedWins(): void
    {
        $policy = new DiagnosticCapturePolicy(maximumAcceptedAttributeCount: 3);

        $accepted = $policy->apply(new DiagnosticEntry(
            'execution-1',
            'http',
            'request',
            array(
                new DiagnosticAttribute('first', DiagnosticDataClassification::PublicOperationalMetadata, 'one'),
                new DiagnosticAttribute('second', DiagnosticDataClassification::PublicOperationalMetadata, 'two'),
                new DiagnosticAttribute('second', DiagnosticDataClassification::PublicOperationalMetadata, 'replacement'),
                new DiagnosticAttribute('third', DiagnosticDataClassification::InternalOperationalMetadata, 'three'),
                new DiagnosticAttribute('fourth', DiagnosticDataClassification::PublicOperationalMetadata, 'four'),
            ),
        ));

        self::assertNotNull($accepted);
        self::assertSame(array('first', 'second', 'third'), $this->attributeNames($accepted));
        self::assertSame('two', $accepted->attributes()[1]->value());
    }

    public function testRedactorNullResultSuppressesAttributeAndThrowingRedactorDoesNotRetainOriginalValue(): void
    {
        $policy = new DiagnosticCapturePolicy(redactor: new SelectiveFailingRedactor());

        $accepted = $policy->apply(new DiagnosticEntry(
            'execution-1',
            'http',
            'request',
            array(
                new DiagnosticAttribute('drop', DiagnosticDataClassification::PublicOperationalMetadata, 'drop-value'),
                new DiagnosticAttribute('throw', DiagnosticDataClassification::PublicOperationalMetadata, 'throw-value'),
                new DiagnosticAttribute('keep', DiagnosticDataClassification::PublicOperationalMetadata, 'safe'),
            ),
        ));

        self::assertNotNull($accepted);
        self::assertSame(array('keep'), $this->attributeNames($accepted));
        self::assertSame('safe', $accepted->attributes()[0]->value());
    }

    public function testEntryBecomesNullWhenNoAcceptableAttributesRemain(): void
    {
        self::assertNull($this->defaultPolicy()->apply(new DiagnosticEntry(
            'execution-1',
            'http',
            'request',
            array(new DiagnosticAttribute('email', DiagnosticDataClassification::PersonalData, 'person@example.com')),
        )));
    }

    public function testFilterAndSamplingRejectionReturnNull(): void
    {
        $filtered = new DiagnosticCapturePolicy(filter: new DiagnosticCaptureFilter(disabledCategories: array('http')));
        $unsampled = new DiagnosticCapturePolicy(sampler: new DeterministicDiagnosticSampler(0));
        $entry = $this->safeEntry('execution-1', 'http', 'request');

        self::assertNull($filtered->apply($entry));
        self::assertNull($unsampled->apply($entry));
    }

    public function testPolicyDoesNotMutateOriginalCandidateAndReturnsSafeMinimizedEntry(): void
    {
        $candidate = new DiagnosticEntry(
            'execution-1',
            'http',
            'request',
            array(
                new DiagnosticAttribute('authorization', DiagnosticDataClassification::PublicOperationalMetadata, 'Bearer token'),
                new DiagnosticAttribute('email', DiagnosticDataClassification::PersonalData, 'person@example.com'),
                new DiagnosticAttribute('route', DiagnosticDataClassification::PublicOperationalMetadata, 'users.show'),
            ),
        );

        $accepted = $this->defaultPolicy()->apply($candidate);

        self::assertNotNull($accepted);
        self::assertSame(array('authorization', 'route'), $this->attributeNames($accepted));
        self::assertSame(DefaultDiagnosticRedactor::REDACTION_MARKER, $accepted->attributes()[0]->value());
        self::assertSame('Bearer token', $candidate->attributes()[0]->value());
        self::assertSame('person@example.com', $candidate->attributes()[1]->value());
    }

    public function testSamplingDecisionIsStableAcrossDifferentlyNamedCandidateEntries(): void
    {
        $policy = new DiagnosticCapturePolicy(sampler: new DeterministicDiagnosticSampler(50));
        $first = $policy->apply($this->safeEntry('execution-stable', 'http', 'request'));
        $second = $policy->apply($this->safeEntry('execution-stable', 'db', 'query'));

        self::assertSame($first instanceof DiagnosticEntry, $second instanceof DiagnosticEntry);
    }

    private function defaultPolicy(): DiagnosticCapturePolicy
    {
        return new DiagnosticCapturePolicy();
    }

    private function nonPrimitiveValue(string $kind): mixed
    {
        if ($kind === 'array') {
            return array('nested' => 'value');
        }

        return new \stdClass();
    }

    private function safeEntry(string $identifier, string $category, string $name): DiagnosticEntry
    {
        return new DiagnosticEntry(
            $identifier,
            $category,
            $name,
            array(new DiagnosticAttribute('route', DiagnosticDataClassification::PublicOperationalMetadata, 'users.show')),
        );
    }

    /**
     * @return list<string>
     */
    private function attributeNames(DiagnosticEntry $entry): array
    {
        return array_map(
            static fn (DiagnosticAttribute $attribute): string => $attribute->name(),
            $entry->attributes(),
        );
    }

    /**
     * @return list<string|int|float|bool|null>
     */
    private function attributeValues(DiagnosticEntry $entry): array
    {
        return array_map(
            static fn (DiagnosticAttribute $attribute): string|int|float|bool|null => $attribute->value(),
            $entry->attributes(),
        );
    }
}

final class SelectiveFailingRedactor implements DiagnosticRedactor
{
    public function redact(DiagnosticAttribute $attribute): ?DiagnosticAttribute
    {
        if ($attribute->name() === 'drop') {
            return null;
        }

        if ($attribute->name() === 'throw') {
            throw new \RuntimeException('redaction failed');
        }

        return $attribute;
    }
}

final class ReclassifyingRedactor implements DiagnosticRedactor
{
    public function redact(DiagnosticAttribute $attribute): DiagnosticAttribute
    {
        return match ($attribute->name()) {
            'secret' => new DiagnosticAttribute('secret', DiagnosticDataClassification::SecretData, 'returned-secret'),
            'authentication' => new DiagnosticAttribute('authentication', DiagnosticDataClassification::AuthenticationData, 'returned-authentication'),
            'personal' => new DiagnosticAttribute('personal', DiagnosticDataClassification::PersonalData, 'returned-personal'),
            default => $attribute,
        };
    }
}
