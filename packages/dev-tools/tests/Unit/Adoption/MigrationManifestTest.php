<?php

declare(strict_types=1);

namespace Evolve\DevTools\Tests\Unit\Adoption;

use Evolve\DevTools\Adoption\DataMigrationState;
use Evolve\DevTools\Adoption\DataOwnership;
use Evolve\DevTools\Adoption\IntegrationMode;
use Evolve\DevTools\Adoption\MigrationManifest;
use Evolve\DevTools\Adoption\OwnershipSystem;
use Evolve\DevTools\Adoption\RouteOwner;
use Evolve\DevTools\Adoption\RouteOwnership;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MigrationManifestTest extends TestCase
{
    public function testEnumVocabularyIsExact(): void
    {
        self::assertSame(['embedded', 'remote'], $this->backingValues(IntegrationMode::class));
        self::assertFalse(defined(IntegrationMode::class . '::Sidecar'));

        self::assertSame(['host', 'evolve'], $this->backingValues(OwnershipSystem::class));
        self::assertFalse(defined(OwnershipSystem::class . '::Both'));

        self::assertSame(['host', 'evolve', 'disabled'], $this->backingValues(RouteOwner::class));
        self::assertSame(
            [
                'legacy_authoritative',
                'migration_syncing',
                'evolve_authoritative',
                'legacy_read_only',
                'legacy_retired',
            ],
            $this->backingValues(DataMigrationState::class),
        );
    }

    public function testRouteOwnershipPreservesValidDeclaration(): void
    {
        $ownership = new RouteOwnership(
            '  billing.dashboard  ',
            RouteOwner::Host,
            RouteOwner::Evolve,
        );

        self::assertSame('  billing.dashboard  ', $ownership->route());
        self::assertSame(RouteOwner::Host, $ownership->currentOwner());
        self::assertSame(RouteOwner::Evolve, $ownership->targetOwner());
    }

    public function testRouteOwnershipRejectsEmptyRoute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Route identifier must be non-empty.');

        new RouteOwnership('', RouteOwner::Host, RouteOwner::Evolve);
    }

    public function testRouteOwnershipRejectsWhitespaceOnlyRoute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Route identifier must be non-empty.');

        new RouteOwnership(" \t\n", RouteOwner::Host, RouteOwner::Evolve);
    }

    public function testDataOwnershipPreservesCompleteValidDeclaration(): void
    {
        $ownership = new DataOwnership(
            '  invoices  ',
            ' legacy.billing ',
            ' evolve.billing ',
            OwnershipSystem::Host,
            OwnershipSystem::Evolve,
            DataMigrationState::LegacyAuthoritative,
            DataMigrationState::MigrationSyncing,
            ' nightly export ',
        );

        self::assertSame('  invoices  ', $ownership->dataSet());
        self::assertSame(' legacy.billing ', $ownership->currentStore());
        self::assertSame(' evolve.billing ', $ownership->targetStore());
        self::assertSame(OwnershipSystem::Host, $ownership->currentWriter());
        self::assertSame(OwnershipSystem::Evolve, $ownership->targetWriter());
        self::assertSame(DataMigrationState::LegacyAuthoritative, $ownership->currentState());
        self::assertSame(DataMigrationState::MigrationSyncing, $ownership->targetState());
        self::assertSame(' nightly export ', $ownership->temporarySynchronization());
    }

    public function testDataOwnershipRejectsBlankDataSet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Data set identifier must be non-empty.');

        new DataOwnership(
            ' ',
            'legacy',
            'evolve',
            OwnershipSystem::Host,
            OwnershipSystem::Evolve,
            DataMigrationState::LegacyAuthoritative,
            DataMigrationState::MigrationSyncing,
            null,
        );
    }

    public function testDataOwnershipRejectsBlankCurrentStore(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Current store must be non-empty.');

        new DataOwnership(
            'invoices',
            '',
            'evolve',
            OwnershipSystem::Host,
            OwnershipSystem::Evolve,
            DataMigrationState::LegacyAuthoritative,
            DataMigrationState::MigrationSyncing,
            null,
        );
    }

    public function testDataOwnershipRejectsBlankTargetStore(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Target store must be non-empty.');

        new DataOwnership(
            'invoices',
            'legacy',
            "\t",
            OwnershipSystem::Host,
            OwnershipSystem::Evolve,
            DataMigrationState::LegacyAuthoritative,
            DataMigrationState::MigrationSyncing,
            null,
        );
    }

    public function testDataOwnershipRejectsBlankSuppliedTemporarySynchronization(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Temporary synchronization must be non-empty when supplied.');

        new DataOwnership(
            'invoices',
            'legacy',
            'evolve',
            OwnershipSystem::Host,
            OwnershipSystem::Evolve,
            DataMigrationState::LegacyAuthoritative,
            DataMigrationState::MigrationSyncing,
            " \n",
        );
    }

    public function testDataOwnershipAcceptsNullTemporarySynchronization(): void
    {
        $ownership = new DataOwnership(
            'invoices',
            'legacy',
            'evolve',
            OwnershipSystem::Host,
            OwnershipSystem::Evolve,
            DataMigrationState::LegacyAuthoritative,
            DataMigrationState::EvolveAuthoritative,
            null,
        );

        self::assertNull($ownership->temporarySynchronization());
    }

    public function testDataOwnershipKeepsWriterDeclarationExplicitWithoutStateInference(): void
    {
        $ownership = new DataOwnership(
            'invoices',
            'legacy',
            'evolve',
            OwnershipSystem::Evolve,
            OwnershipSystem::Host,
            DataMigrationState::LegacyAuthoritative,
            DataMigrationState::EvolveAuthoritative,
            null,
        );

        self::assertSame(OwnershipSystem::Evolve, $ownership->currentWriter());
        self::assertSame(OwnershipSystem::Host, $ownership->targetWriter());
        self::assertSame(DataMigrationState::LegacyAuthoritative, $ownership->currentState());
        self::assertSame(DataMigrationState::EvolveAuthoritative, $ownership->targetState());
    }

    public function testManifestPreservesValidDeclaration(): void
    {
        $firstRoute = new RouteOwnership('billing.index', RouteOwner::Host, RouteOwner::Evolve);
        $secondRoute = new RouteOwnership('billing.reports', RouteOwner::Disabled, RouteOwner::Host);
        $firstData = $this->dataOwnership('invoices');
        $secondData = $this->dataOwnership('payments');

        $manifest = new MigrationManifest(
            '  Billing reports  ',
            IntegrationMode::Remote,
            [$firstRoute, $secondRoute],
            [$firstData, $secondData],
            ['Requires PHP 8.4 host adapter.', 'Preserves existing URLs.'],
            ['Preserves authenticated user identity.', 'Maintains authorization policy.'],
        );

        self::assertSame('  Billing reports  ', $manifest->capability());
        self::assertSame(IntegrationMode::Remote, $manifest->integrationMode());
        self::assertSame([$firstRoute, $secondRoute], $manifest->routeOwnership());
        self::assertSame([$firstData, $secondData], $manifest->dataOwnership());
        self::assertSame(['Requires PHP 8.4 host adapter.', 'Preserves existing URLs.'], $manifest->compatibilityRequirements());
        self::assertSame(['Preserves authenticated user identity.', 'Maintains authorization policy.'], $manifest->identitySecurityRequirements());
    }

    public function testManifestRejectsNonListRouteCollection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Route ownership must be a list.');

        new MigrationManifest('billing', IntegrationMode::Embedded, ['named' => new RouteOwnership('billing.index', RouteOwner::Host, RouteOwner::Evolve)], [], [], []);
    }

    public function testManifestRejectsNonListDataCollection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Data ownership must be a list.');

        new MigrationManifest('billing', IntegrationMode::Embedded, [], ['named' => $this->dataOwnership('invoices')], [], []);
    }

    public function testManifestRejectsNonListCompatibilityRequirements(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Compatibility requirements must be a list.');

        new MigrationManifest('billing', IntegrationMode::Embedded, [], [], ['named' => 'compatible'], []);
    }

    public function testManifestRejectsNonListSecurityRequirements(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Identity and security requirements must be a list.');

        new MigrationManifest('billing', IntegrationMode::Embedded, [], [], [], ['named' => 'secure']);
    }

    public function testManifestRejectsWrongRouteElementType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Route ownership may contain only route ownership declarations.');

        new MigrationManifest('billing', IntegrationMode::Embedded, ['billing.index'], [], [], []);
    }

    public function testManifestRejectsWrongDataElementType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Data ownership may contain only data ownership declarations.');

        new MigrationManifest('billing', IntegrationMode::Embedded, [], ['invoices'], [], []);
    }

    public function testManifestRejectsNonStringRequirement(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Compatibility requirements may contain only non-empty strings.');

        new MigrationManifest('billing', IntegrationMode::Embedded, [], [], [123], []);
    }

    public function testManifestRejectsBlankCompatibilityRequirement(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Compatibility requirements may contain only non-empty strings.');

        new MigrationManifest('billing', IntegrationMode::Embedded, [], [], [' '], []);
    }

    public function testManifestRejectsBlankSecurityRequirement(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Identity and security requirements may contain only non-empty strings.');

        new MigrationManifest('billing', IntegrationMode::Embedded, [], [], [], ["\n"]);
    }

    public function testManifestRejectsDuplicateRouteIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Route ownership contains duplicate route identifiers.');

        new MigrationManifest(
            'billing',
            IntegrationMode::Embedded,
            [
                new RouteOwnership('billing.index', RouteOwner::Host, RouteOwner::Evolve),
                new RouteOwnership('billing.index', RouteOwner::Evolve, RouteOwner::Host),
            ],
            [],
            [],
            [],
        );
    }

    public function testManifestRejectsDuplicateDatasetIdentifier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Data ownership contains duplicate data set identifiers.');

        new MigrationManifest(
            'billing',
            IntegrationMode::Embedded,
            [],
            [$this->dataOwnership('invoices'), $this->dataOwnership('invoices')],
            [],
            [],
        );
    }

    public function testManifestRejectsBlankCapability(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Capability name must be non-empty.');

        new MigrationManifest(' ', IntegrationMode::Embedded, [], [], [], []);
    }

    /**
     * @param class-string<\BackedEnum> $enum
     *
     * @return list<int|string>
     */
    private function backingValues(string $enum): array
    {
        return array_map(
            static fn(\ReflectionEnumBackedCase $case): int|string => $case->getBackingValue(),
            (new \ReflectionEnum($enum))->getCases(),
        );
    }

    private function dataOwnership(string $dataSet): DataOwnership
    {
        return new DataOwnership(
            $dataSet,
            'legacy',
            'evolve',
            OwnershipSystem::Host,
            OwnershipSystem::Evolve,
            DataMigrationState::LegacyAuthoritative,
            DataMigrationState::MigrationSyncing,
            null,
        );
    }
}
