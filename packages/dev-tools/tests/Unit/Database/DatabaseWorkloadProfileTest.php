<?php

declare(strict_types=1);

namespace Evolve\DevTools\Tests\Unit\Database;

use Evolve\DevTools\Database\DatabaseCapability;
use Evolve\DevTools\Database\DatabaseWorkloadProfile;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DatabaseWorkloadProfileTest extends TestCase
{
    public function testProfilePreservesNameAndCanonicalizesCapabilityCollections(): void
    {
        $profile = new DatabaseWorkloadProfile(
            '  Reporting  ',
            [DatabaseCapability::Joins, DatabaseCapability::AcidTransactions],
            [DatabaseCapability::ManagedDeployment, DatabaseCapability::AnalyticalScans],
            [DatabaseCapability::EventualConsistency, DatabaseCapability::Ttl],
        );

        self::assertSame('  Reporting  ', $profile->name());
        self::assertSame([DatabaseCapability::AcidTransactions, DatabaseCapability::Joins], $profile->requiredCapabilities());
        self::assertSame([DatabaseCapability::AnalyticalScans, DatabaseCapability::ManagedDeployment], $profile->preferredCapabilities());
        self::assertSame([DatabaseCapability::EventualConsistency, DatabaseCapability::Ttl], $profile->excludedCapabilities());
    }

    public function testProfileRejectsBlankName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database workload profile name must be non-empty.');

        new DatabaseWorkloadProfile(' ', [DatabaseCapability::Joins], [], []);
    }

    public function testProfileRequiresAtLeastOneCapability(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database workload profile must contain at least one capability.');

        new DatabaseWorkloadProfile('Reporting', [], [], []);
    }

    public function testProfileRejectsNonListCapabilityCollections(): void
    {
        foreach (
            [
                [['named' => DatabaseCapability::Joins], [], [], 'Required database capabilities must be a list.'],
                [[], ['named' => DatabaseCapability::Joins], [], 'Preferred database capabilities must be a list.'],
                [[], [], ['named' => DatabaseCapability::Joins], 'Excluded database capabilities must be a list.'],
            ] as [$required, $preferred, $excluded, $message]
        ) {
            try {
                new DatabaseWorkloadProfile('Reporting', $required, $preferred, $excluded);
            } catch (InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());

                continue;
            }

            self::fail('Non-list capability collection should have been rejected.');
        }
    }

    public function testProfileRejectsWrongCapabilityTypes(): void
    {
        foreach (
            [
                [['joins'], [], [], 'Required database capabilities may contain only database capabilities.'],
                [[], ['joins'], [], 'Preferred database capabilities may contain only database capabilities.'],
                [[], [], ['joins'], 'Excluded database capabilities may contain only database capabilities.'],
            ] as [$required, $preferred, $excluded, $message]
        ) {
            try {
                new DatabaseWorkloadProfile('Reporting', $required, $preferred, $excluded);
            } catch (InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());

                continue;
            }

            self::fail('Wrong capability type should have been rejected.');
        }
    }

    public function testProfileRejectsDuplicateCapabilityInsideEachCollection(): void
    {
        foreach (
            [
                [[DatabaseCapability::Joins, DatabaseCapability::Joins], [], [], 'Required database capabilities contain duplicate capabilities.'],
                [[], [DatabaseCapability::Joins, DatabaseCapability::Joins], [], 'Preferred database capabilities contain duplicate capabilities.'],
                [[], [], [DatabaseCapability::Ttl, DatabaseCapability::Ttl], 'Excluded database capabilities contain duplicate capabilities.'],
            ] as [$required, $preferred, $excluded, $message]
        ) {
            try {
                new DatabaseWorkloadProfile('Reporting', $required, $preferred, $excluded);
            } catch (InvalidArgumentException $exception) {
                self::assertSame($message, $exception->getMessage());

                continue;
            }

            self::fail('Duplicate capability should have been rejected.');
        }
    }

    public function testProfileRejectsCapabilityDeclaredInMultipleCollections(): void
    {
        foreach (
            [
                [[DatabaseCapability::Joins], [DatabaseCapability::Joins], []],
                [[DatabaseCapability::Joins], [], [DatabaseCapability::Joins]],
                [[], [DatabaseCapability::Joins], [DatabaseCapability::Joins]],
            ] as [$required, $preferred, $excluded]
        ) {
            try {
                new DatabaseWorkloadProfile('Reporting', $required, $preferred, $excluded);
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Database workload profile capability collections must be pairwise disjoint.', $exception->getMessage());

                continue;
            }

            self::fail('Overlapping capability collections should have been rejected.');
        }
    }
}
