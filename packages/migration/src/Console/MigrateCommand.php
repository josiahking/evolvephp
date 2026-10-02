<?php

declare(strict_types=1);

namespace Evolve\Migration\Console;

use Evolve\Core\Console\Command;
use Evolve\Core\Console\CommandInput;
use Evolve\Core\Console\CommandOutput;
use Evolve\Core\Console\CommandResult;
use Evolve\Migration\MigrationPlan;
use Evolve\Migration\MigrationPlanner;
use Evolve\Migration\MigrationPlanStatus;
use Evolve\Migration\MigrationRunner;
use Throwable;

/** Explicit CLI adapter; outer Core command composition owns the CLI execution scope. */
final readonly class MigrateCommand implements Command
{
    public function __construct(private MigrationPlanner $planner, private MigrationRunner $runner) {}

    public function name(): string
    {
        return 'migrate';
    }

    public function description(): string
    {
        return 'Inspect or apply pending migrations.';
    }

    public function execute(CommandInput $input, CommandOutput $output): CommandResult
    {
        $tokens = $input->tokens();
        if ($tokens !== [] && $tokens !== ['--dry-run'] && $tokens !== ['--status']) {
            $output->writeError('Invalid migrate input.');
            return new CommandResult(2);
        }

        if ($tokens !== []) {
            try {
                $plan = $this->planner->plan();
            } catch (Throwable) {
                $output->writeError('Migration status unavailable.');
                return new CommandResult(1);
            }
            $label = $tokens === ['--dry-run'] ? 'Migration dry-run' : 'Migration status';
            $output->write($label . ': ' . $this->summary($plan));
            return new CommandResult(0);
        }

        try {
            $report = $this->runner->run();
        } catch (Throwable) {
            $output->writeError('Migration run unavailable.');
            return new CommandResult(1);
        }
        if ($report->contended()) {
            $output->write('Migration lock busy.');
            return new CommandResult(2);
        }
        if ($report->blocked()) {
            $output->writeError('Migrations blocked.');
            return new CommandResult(1);
        }
        if ($report->uncertain()) {
            $output->writeError('Migration state uncertain.');
            return new CommandResult(1);
        }
        if ($report->cleanupThrowable() !== null) {
            $output->writeError('Migration cleanup failed.');
            return new CommandResult(1);
        }
        if ($report->primaryThrowable() !== null) {
            $output->writeError('Migration failed.');
            return new CommandResult(1);
        }

        $output->write('Migrations completed: ' . count($report->recorded()) . ' recorded.');
        return new CommandResult(0);
    }

    private function summary(MigrationPlan $plan): string
    {
        $pending = 0;
        $applied = 0;
        $drifted = 0;
        $orphaned = 0;
        foreach ($plan->entries() as $entry) {
            match ($entry->status()) {
                MigrationPlanStatus::Pending => ++$pending,
                MigrationPlanStatus::Applied => ++$applied,
                MigrationPlanStatus::Drifted => ++$drifted,
                MigrationPlanStatus::OrphanedApplied => ++$orphaned,
            };
        }
        return "pending=$pending, applied=$applied, drifted=$drifted, orphaned-applied=$orphaned";
    }
}
