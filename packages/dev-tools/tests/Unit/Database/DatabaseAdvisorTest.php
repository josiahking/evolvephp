<?php

declare(strict_types=1);

namespace Evolve\DevTools\Tests\Unit\Database;

use Evolve\DevTools\Database\DatabaseAdvisor;
use Evolve\DevTools\Database\DatabaseCapability;
use Evolve\DevTools\Database\DatabaseCatalog;
use Evolve\DevTools\Database\DatabaseFamilyDefinition;
use Evolve\DevTools\Database\DatabaseWorkloadProfile;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DatabaseAdvisorTest extends TestCase
{
    public function testAdvisorReturnsExplainableRecommendationsAlternativesAndDisqualifiedFamilies(): void
    {
        $advisor = new DatabaseAdvisor(new DatabaseCatalog(
            [
                $this->family('document', [DatabaseCapability::SchemaFlexibility, DatabaseCapability::HorizontalScale]),
                $this->family('relational', [DatabaseCapability::AcidTransactions, DatabaseCapability::Joins, DatabaseCapability::ReadIntensive]),
                $this->family('distributed-sql', [DatabaseCapability::AcidTransactions, DatabaseCapability::Joins, DatabaseCapability::HorizontalScale]),
                $this->family('key-value', [DatabaseCapability::HorizontalScale, DatabaseCapability::Ttl]),
            ],
        ));

        $advice = $advisor->advise(new DatabaseWorkloadProfile(
            'Orders',
            [DatabaseCapability::AcidTransactions],
            [DatabaseCapability::Joins, DatabaseCapability::HorizontalScale],
            [DatabaseCapability::Ttl],
        ));

        self::assertSame(['distributed-sql'], $this->assessmentIdentifiers($advice->recommendations()));
        self::assertSame(['relational'], $this->assessmentIdentifiers($advice->alternatives()));
        self::assertSame(['document', 'key-value'], $this->assessmentIdentifiers($advice->disqualified()));

        $recommendation = $advice->recommendations()[0];
        self::assertTrue($recommendation->suitable());
        self::assertSame(2, $recommendation->preferenceMatchCount());
        self::assertSame([DatabaseCapability::AcidTransactions], $recommendation->matchedRequiredCapabilities());
        self::assertSame([], $recommendation->missingRequiredCapabilities());
        self::assertSame([DatabaseCapability::HorizontalScale, DatabaseCapability::Joins], $recommendation->matchedPreferredCapabilities());
        self::assertSame([], $recommendation->missingPreferredCapabilities());
        self::assertSame([], $recommendation->conflictingExcludedCapabilities());
    }

    public function testDisqualifiedAssessmentExplainsMissingRequiredCapabilities(): void
    {
        $advisor = new DatabaseAdvisor(new DatabaseCatalog([
            $this->family('document', [DatabaseCapability::SchemaFlexibility]),
        ]));

        $assessment = $advisor->advise(new DatabaseWorkloadProfile(
            'Orders',
            [DatabaseCapability::AcidTransactions, DatabaseCapability::Joins],
            [],
            [],
        ))->disqualified()[0];

        self::assertFalse($assessment->suitable());
        self::assertSame([], $this->capabilityValues($assessment->matchedRequiredCapabilities()));
        self::assertSame(['acid-transactions', 'joins'], $this->capabilityValues($assessment->missingRequiredCapabilities()));
    }

    public function testDisqualifiedAssessmentExplainsExcludedCapabilityConflicts(): void
    {
        $advisor = new DatabaseAdvisor(new DatabaseCatalog([
            $this->family('cache', [DatabaseCapability::LowLatency, DatabaseCapability::Ttl]),
        ]));

        $assessment = $advisor->advise(new DatabaseWorkloadProfile(
            'Session Store',
            [DatabaseCapability::LowLatency],
            [],
            [DatabaseCapability::Ttl],
        ))->disqualified()[0];

        self::assertFalse($assessment->suitable());
        self::assertSame(['ttl'], $this->capabilityValues($assessment->conflictingExcludedCapabilities()));
    }

    public function testPreferredCapabilityExplanationAndCountAreExact(): void
    {
        $advisor = new DatabaseAdvisor(new DatabaseCatalog([
            $this->family('relational', [DatabaseCapability::Joins, DatabaseCapability::ReadIntensive]),
        ]));

        $assessment = $advisor->advise(new DatabaseWorkloadProfile(
            'Reporting',
            [DatabaseCapability::Joins],
            [DatabaseCapability::AnalyticalScans, DatabaseCapability::ReadIntensive],
            [],
        ))->recommendations()[0];

        self::assertSame(['read-intensive'], $this->capabilityValues($assessment->matchedPreferredCapabilities()));
        self::assertSame(['analytical-scans'], $this->capabilityValues($assessment->missingPreferredCapabilities()));
        self::assertSame(1, $assessment->preferenceMatchCount());
    }

    public function testAlternativeOrderingUsesPreferenceCountThenIdentifier(): void
    {
        $advisor = new DatabaseAdvisor(new DatabaseCatalog([
            $this->family('charlie', [DatabaseCapability::Joins, DatabaseCapability::ReadIntensive]),
            $this->family('alpha', [DatabaseCapability::Joins, DatabaseCapability::ManagedDeployment]),
            $this->family('bravo', [DatabaseCapability::Joins, DatabaseCapability::ManagedDeployment]),
            $this->family('omega', [DatabaseCapability::Joins, DatabaseCapability::ReadIntensive, DatabaseCapability::ManagedDeployment]),
            $this->family('delta', [DatabaseCapability::Joins]),
        ]));

        $advice = $advisor->advise(new DatabaseWorkloadProfile(
            'Reporting',
            [DatabaseCapability::Joins],
            [DatabaseCapability::ReadIntensive, DatabaseCapability::ManagedDeployment],
            [],
        ));

        self::assertSame(['omega'], $this->assessmentIdentifiers($advice->recommendations()));
        self::assertSame(['alpha', 'bravo', 'charlie', 'delta'], $this->assessmentIdentifiers($advice->alternatives()));
    }

    public function testRecommendationTieOrderingUsesIdentifier(): void
    {
        $advisor = new DatabaseAdvisor(new DatabaseCatalog([
            $this->family('zeta', [DatabaseCapability::Joins, DatabaseCapability::ReadIntensive]),
            $this->family('alpha', [DatabaseCapability::Joins, DatabaseCapability::ReadIntensive]),
        ]));

        $advice = $advisor->advise(new DatabaseWorkloadProfile(
            'Reporting',
            [DatabaseCapability::Joins],
            [DatabaseCapability::ReadIntensive],
            [],
        ));

        self::assertSame(['alpha', 'zeta'], $this->assessmentIdentifiers($advice->recommendations()));
    }

    public function testDisqualifiedOrderingUsesIdentifierIndependentOfCatalogInputOrder(): void
    {
        $advisor = new DatabaseAdvisor(new DatabaseCatalog([
            $this->family('zeta', [DatabaseCapability::SchemaFlexibility]),
            $this->family('alpha', [DatabaseCapability::SchemaFlexibility]),
        ]));

        $advice = $advisor->advise(new DatabaseWorkloadProfile(
            'Vectors',
            [DatabaseCapability::VectorSimilarity],
            [],
            [],
        ));

        self::assertSame(['alpha', 'zeta'], $this->assessmentIdentifiers($advice->disqualified()));
    }

    public function testEveryViableFamilyTiesWhenNoPreferredCapabilitiesExist(): void
    {
        $advisor = new DatabaseAdvisor(new DatabaseCatalog(
            [
                $this->family('relational', [DatabaseCapability::AcidTransactions]),
                $this->family('ledger', [DatabaseCapability::AcidTransactions, DatabaseCapability::ImmutableHistory]),
                $this->family('cache', [DatabaseCapability::Ttl]),
            ],
        ));

        $advice = $advisor->advise(new DatabaseWorkloadProfile(
            'Transactions',
            [DatabaseCapability::AcidTransactions],
            [],
            [],
        ));

        self::assertSame(['ledger', 'relational'], $this->assessmentIdentifiers($advice->recommendations()));
        self::assertSame([], $advice->alternatives());
        self::assertSame(['cache'], $this->assessmentIdentifiers($advice->disqualified()));
    }

    public function testAdvisorDoesNotCreateFallbackWinnerWhenNoFamilyIsViable(): void
    {
        $advisor = new DatabaseAdvisor(new DatabaseCatalog(
            [
                $this->family('document', [DatabaseCapability::SchemaFlexibility]),
                $this->family('relational', [DatabaseCapability::Joins]),
            ],
        ));

        $advice = $advisor->advise(new DatabaseWorkloadProfile(
            'Vectors',
            [DatabaseCapability::VectorSimilarity],
            [],
            [],
        ));

        self::assertSame([], $advice->recommendations());
        self::assertSame([], $advice->alternatives());
        self::assertSame(['document', 'relational'], $this->assessmentIdentifiers($advice->disqualified()));
    }

    public function testAdviseManyPreservesProfileOrderAndEvaluatesIndependently(): void
    {
        $advisor = new DatabaseAdvisor(DatabaseCatalog::builtIn());
        $transactional = new DatabaseWorkloadProfile('Transactional', [DatabaseCapability::AcidTransactions], [], []);
        $search = new DatabaseWorkloadProfile('Search', [DatabaseCapability::FullTextSearch], [], []);

        $advice = $advisor->adviseMany([$transactional, $search]);

        self::assertSame([$transactional, $search], [$advice[0]->profile(), $advice[1]->profile()]);
        self::assertSame(['distributed-sql', 'ledger-immutable', 'relational'], $this->assessmentIdentifiers($advice[0]->recommendations()));
        self::assertSame(['search-index'], $this->assessmentIdentifiers($advice[1]->recommendations()));
    }

    public function testAdviseManyDoesNotCombinePolyglotProfileScores(): void
    {
        $advisor = new DatabaseAdvisor(DatabaseCatalog::builtIn());
        $search = new DatabaseWorkloadProfile('Search', [DatabaseCapability::FullTextSearch], [DatabaseCapability::VectorSimilarity], []);
        $vector = new DatabaseWorkloadProfile('Vector', [DatabaseCapability::VectorSimilarity], [DatabaseCapability::FullTextSearch], []);

        $advice = $advisor->adviseMany([$search, $vector]);

        self::assertSame(['search-index'], $this->assessmentIdentifiers($advice[0]->recommendations()));
        self::assertSame(['vector'], $this->assessmentIdentifiers($advice[1]->recommendations()));
        self::assertSame(0, $advice[0]->recommendations()[0]->preferenceMatchCount());
        self::assertSame(0, $advice[1]->recommendations()[0]->preferenceMatchCount());
    }

    public function testEquivalentInputsProduceSameStructuralAdvice(): void
    {
        $profile = new DatabaseWorkloadProfile(
            'Reporting',
            [DatabaseCapability::Joins],
            [DatabaseCapability::ReadIntensive, DatabaseCapability::ManagedDeployment],
            [DatabaseCapability::Ttl],
        );
        $firstAdvisor = new DatabaseAdvisor(new DatabaseCatalog([
            $this->family('cache', [DatabaseCapability::Joins, DatabaseCapability::Ttl]),
            $this->family('relational', [DatabaseCapability::Joins, DatabaseCapability::ReadIntensive]),
            $this->family('warehouse', [DatabaseCapability::Joins, DatabaseCapability::ManagedDeployment]),
        ]));
        $secondAdvisor = new DatabaseAdvisor(new DatabaseCatalog([
            $this->family('warehouse', [DatabaseCapability::Joins, DatabaseCapability::ManagedDeployment]),
            $this->family('relational', [DatabaseCapability::Joins, DatabaseCapability::ReadIntensive]),
            $this->family('cache', [DatabaseCapability::Joins, DatabaseCapability::Ttl]),
        ]));

        $expected = $this->normalizeAdvice($firstAdvisor->advise($profile));

        self::assertSame($expected, $this->normalizeAdvice($firstAdvisor->advise($profile)));
        self::assertSame($expected, $this->normalizeAdvice($secondAdvisor->advise($profile)));
    }

    public function testFamilyGuidanceRemainsAccessibleThroughAssessment(): void
    {
        $advisor = new DatabaseAdvisor(DatabaseCatalog::builtIn());

        $assessment = $advisor->advise(new DatabaseWorkloadProfile(
            'Orders',
            [DatabaseCapability::Joins, DatabaseCapability::EmbeddedOffline],
            [],
            [],
        ))->recommendations()[0];

        self::assertSame(['MariaDB', 'MySQL', 'PostgreSQL', 'SQLite'], $assessment->family()->exampleEngines());
        self::assertNotSame([], $assessment->family()->tradeOffs());
    }

    public function testRepresentativeBuiltInScenariosRecommendExpectedFamily(): void
    {
        foreach (
            [
                'relational' => [[DatabaseCapability::Joins, DatabaseCapability::EmbeddedOffline], 'relational'],
                'distributed-sql' => [[DatabaseCapability::Joins, DatabaseCapability::HorizontalScale], 'distributed-sql'],
                'graph' => [[DatabaseCapability::GraphTraversal], 'graph'],
                'vector' => [[DatabaseCapability::VectorSimilarity], 'vector'],
                'search-index' => [[DatabaseCapability::FullTextSearch], 'search-index'],
                'time-series' => [[DatabaseCapability::TimeSeriesRetention], 'time-series'],
            ] as $name => [$required, $expected]
        ) {
            $advice = (new DatabaseAdvisor(DatabaseCatalog::builtIn()))->advise(new DatabaseWorkloadProfile($name, $required, [], []));

            self::assertSame([$expected], $this->assessmentIdentifiers($advice->recommendations()));
        }
    }

    public function testAdviseManyRejectsNonListProfiles(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database workload profiles must be a list.');

        (new DatabaseAdvisor(DatabaseCatalog::builtIn()))->adviseMany(['named' => new DatabaseWorkloadProfile('Search', [DatabaseCapability::FullTextSearch], [], [])]);
    }

    public function testAdviseManyRequiresAtLeastOneProfile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database workload profiles must contain at least one profile.');

        (new DatabaseAdvisor(DatabaseCatalog::builtIn()))->adviseMany([]);
    }

    public function testAdviseManyRejectsWrongProfileType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database workload profiles may contain only workload profiles.');

        (new DatabaseAdvisor(DatabaseCatalog::builtIn()))->adviseMany(['Search']);
    }

    public function testAdviseManyRejectsDuplicateProfileNames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database workload profiles contain duplicate profile names.');

        (new DatabaseAdvisor(DatabaseCatalog::builtIn()))->adviseMany(
            [
                new DatabaseWorkloadProfile('Search', [DatabaseCapability::FullTextSearch], [], []),
                new DatabaseWorkloadProfile('Search', [DatabaseCapability::VectorSimilarity], [], []),
            ],
        );
    }

    /**
     * @param list<DatabaseCapability> $capabilities
     */
    private function family(string $identifier, array $capabilities): DatabaseFamilyDefinition
    {
        return new DatabaseFamilyDefinition($identifier, ucfirst($identifier), $capabilities, [], ['Trade-off.']);
    }

    /**
     * @param list<\Evolve\DevTools\Database\DatabaseFamilyAssessment> $assessments
     *
     * @return list<string>
     */
    private function assessmentIdentifiers(array $assessments): array
    {
        return array_map(
            static fn($assessment): string => $assessment->family()->identifier(),
            $assessments,
        );
    }

    /**
     * @param list<DatabaseCapability> $capabilities
     *
     * @return list<string>
     */
    private function capabilityValues(array $capabilities): array
    {
        return array_map(
            static fn(DatabaseCapability $capability): string => $capability->value,
            $capabilities,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeAdvice(\Evolve\DevTools\Database\DatabaseAdvice $advice): array
    {
        return [
            'profile' => $advice->profile()->name(),
            'recommendations' => $this->normalizeAssessments($advice->recommendations()),
            'alternatives' => $this->normalizeAssessments($advice->alternatives()),
            'disqualified' => $this->normalizeAssessments($advice->disqualified()),
        ];
    }

    /**
     * @param list<\Evolve\DevTools\Database\DatabaseFamilyAssessment> $assessments
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeAssessments(array $assessments): array
    {
        return array_map(
            fn($assessment): array => [
                'family' => $assessment->family()->identifier(),
                'suitable' => $assessment->suitable(),
                'score' => $assessment->preferenceMatchCount(),
                'matchedRequired' => $this->capabilityValues($assessment->matchedRequiredCapabilities()),
                'missingRequired' => $this->capabilityValues($assessment->missingRequiredCapabilities()),
                'matchedPreferred' => $this->capabilityValues($assessment->matchedPreferredCapabilities()),
                'missingPreferred' => $this->capabilityValues($assessment->missingPreferredCapabilities()),
                'conflictingExcluded' => $this->capabilityValues($assessment->conflictingExcludedCapabilities()),
            ],
            $assessments,
        );
    }
}
