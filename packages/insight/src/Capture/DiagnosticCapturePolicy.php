<?php

declare(strict_types=1);

namespace Evolve\Insight\Capture;

final class DiagnosticCapturePolicy
{
    public const int DEFAULT_MAXIMUM_ACCEPTED_ATTRIBUTE_COUNT = 16;

    private DiagnosticRedactor $redactor;

    private DiagnosticCaptureFilter $filter;

    private DeterministicDiagnosticSampler $sampler;

    /**
     * @var array<string, true>
     */
    private array $acceptedClassifications = array();

    /**
     * @param list<DiagnosticDataClassification>|null $acceptedClassifications
     */
    public function __construct(
        ?DiagnosticRedactor $redactor = null,
        ?DiagnosticCaptureFilter $filter = null,
        ?DeterministicDiagnosticSampler $sampler = null,
        ?array $acceptedClassifications = null,
        private int $maximumAcceptedAttributeCount = self::DEFAULT_MAXIMUM_ACCEPTED_ATTRIBUTE_COUNT,
    ) {
        if ($this->maximumAcceptedAttributeCount <= 0 || $this->maximumAcceptedAttributeCount > DiagnosticEntry::MAX_ATTRIBUTE_COUNT) {
            throw new \InvalidArgumentException('Maximum accepted diagnostic attribute count must be positive and bounded.');
        }

        $this->redactor = $redactor ?? new DefaultDiagnosticRedactor();
        $this->filter = $filter ?? new DiagnosticCaptureFilter();
        $this->sampler = $sampler ?? new DeterministicDiagnosticSampler(100);

        foreach ($acceptedClassifications ?? $this->defaultAcceptedClassifications() as $classification) {
            if (
                $classification === DiagnosticDataClassification::SecretData
                || $classification === DiagnosticDataClassification::AuthenticationData
            ) {
                continue;
            }

            $this->acceptedClassifications[$classification->value] = true;
        }
    }

    public function apply(DiagnosticEntry $candidate): ?DiagnosticEntry
    {
        $attributes = array();
        $acceptedNames = array();

        foreach ($candidate->attributes() as $attribute) {
            if (!isset($this->acceptedClassifications[$attribute->classification()->value])) {
                continue;
            }

            try {
                $redacted = $this->redactor->redact($attribute);
            } catch (\Throwable) {
                continue;
            }

            if ($redacted === null || !isset($this->acceptedClassifications[$redacted->classification()->value])) {
                continue;
            }

            if (isset($acceptedNames[$redacted->name()])) {
                continue;
            }

            $attributes[] = $redacted;
            $acceptedNames[$redacted->name()] = true;

            if (count($attributes) >= $this->maximumAcceptedAttributeCount) {
                break;
            }
        }

        if ($attributes === array()) {
            return null;
        }

        $accepted = new DiagnosticEntry(
            $candidate->executionIdentifier(),
            $candidate->category(),
            $candidate->name(),
            $attributes,
        );

        if (!$this->filter->allows($accepted)) {
            return null;
        }

        if (!$this->sampler->accepts($accepted->executionIdentifier())) {
            return null;
        }

        return $accepted;
    }

    /**
     * @return list<DiagnosticDataClassification>
     */
    private function defaultAcceptedClassifications(): array
    {
        return array(
            DiagnosticDataClassification::PublicOperationalMetadata,
            DiagnosticDataClassification::InternalOperationalMetadata,
        );
    }
}
