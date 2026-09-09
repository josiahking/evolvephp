<?php

declare(strict_types=1);

namespace Evolve\DevTools\Tests\Unit\Adoption;

use Evolve\DevTools\Adoption\AdoptionPlan;
use Evolve\DevTools\Adoption\IntegrationMode;
use Evolve\DevTools\Adoption\MigrationManifest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AdoptionPlanTest extends TestCase
{
    public function testPlanPreservesValidDeclaration(): void
    {
        $manifest = new MigrationManifest(
            'billing',
            IntegrationMode::Embedded,
            [],
            [],
            ['Runs on supported host PHP version.'],
            ['Preserves authenticated user identity.'],
        );

        $plan = new AdoptionPlan(
            $manifest,
            ['Migration dry run recorded.', 'Imported sample tenant data.'],
            ['Rollback dry run recorded.', 'Legacy route restored in staging.'],
            ['Invoices list returns matching totals.', 'No unauthorized user can view invoices.'],
        );

        self::assertSame($manifest, $plan->manifest());
        self::assertSame(['Migration dry run recorded.', 'Imported sample tenant data.'], $plan->migrationEvidence());
        self::assertSame(['Rollback dry run recorded.', 'Legacy route restored in staging.'], $plan->rollbackEvidence());
        self::assertSame(['Invoices list returns matching totals.', 'No unauthorized user can view invoices.'], $plan->acceptanceCriteria());
    }

    public function testPlanRejectsNonListMigrationEvidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Migration evidence must be a list.');

        new AdoptionPlan($this->manifest(), ['named' => 'evidence'], ['rollback'], ['criterion']);
    }

    public function testPlanRejectsNonListRollbackEvidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rollback evidence must be a list.');

        new AdoptionPlan($this->manifest(), ['evidence'], ['named' => 'rollback'], ['criterion']);
    }

    public function testPlanRejectsNonListAcceptanceCriteria(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Acceptance criteria must be a list.');

        new AdoptionPlan($this->manifest(), ['evidence'], ['rollback'], ['named' => 'criterion']);
    }

    public function testPlanRejectsWrongElementType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Migration evidence may contain only non-empty strings.');

        new AdoptionPlan($this->manifest(), [false], ['rollback'], ['criterion']);
    }

    public function testPlanRejectsBlankEvidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Migration evidence may contain only non-empty strings.');

        new AdoptionPlan($this->manifest(), [" \t"], ['rollback'], ['criterion']);
    }

    public function testPlanRejectsBlankCriterion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Acceptance criteria may contain only non-empty strings.');

        new AdoptionPlan($this->manifest(), ['evidence'], ['rollback'], ['']);
    }

    public function testPlanRejectsDuplicateMigrationEvidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Migration evidence contains duplicate declarations.');

        new AdoptionPlan($this->manifest(), ['evidence', 'evidence'], ['rollback'], ['criterion']);
    }

    public function testPlanRejectsDuplicateRollbackEvidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rollback evidence contains duplicate declarations.');

        new AdoptionPlan($this->manifest(), ['evidence'], ['rollback', 'rollback'], ['criterion']);
    }

    public function testPlanRejectsDuplicateAcceptanceCriteria(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Acceptance criteria contains duplicate declarations.');

        new AdoptionPlan($this->manifest(), ['evidence'], ['rollback'], ['criterion', 'criterion']);
    }

    public function testPlanRejectsEmptyMigrationEvidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Migration evidence must contain at least one declaration.');

        new AdoptionPlan($this->manifest(), [], ['rollback'], ['criterion']);
    }

    public function testPlanRejectsEmptyRollbackEvidence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rollback evidence must contain at least one declaration.');

        new AdoptionPlan($this->manifest(), ['evidence'], [], ['criterion']);
    }

    public function testPlanRejectsEmptyAcceptanceCriteria(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Acceptance criteria must contain at least one declaration.');

        new AdoptionPlan($this->manifest(), ['evidence'], ['rollback'], []);
    }

    private function manifest(): MigrationManifest
    {
        return new MigrationManifest(
            'billing',
            IntegrationMode::Embedded,
            [],
            [],
            ['Runs on supported host PHP version.'],
            ['Preserves authenticated user identity.'],
        );
    }
}
