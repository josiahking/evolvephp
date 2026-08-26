<?php

declare(strict_types=1);

namespace Evolve\Core\Doctor\Console;

use Evolve\Core\Console\Command;
use Evolve\Core\Console\CommandInput;
use Evolve\Core\Console\CommandOutput;
use Evolve\Core\Console\CommandResult;
use Evolve\Core\Doctor\DoctorFinding;
use Evolve\Core\Doctor\DoctorRunner;

final readonly class DoctorCommand implements Command
{
    public function __construct(
        private DoctorRunner $doctor,
    ) {}

    public function name(): string
    {
        return 'doctor';
    }

    public function description(): string
    {
        return 'Run configured Evolve Doctor diagnostic checks.';
    }

    public function execute(CommandInput $input, CommandOutput $output): CommandResult
    {
        if ($input->tokens() !== []) {
            $output->writeError('The doctor command does not accept arguments or options.');

            return new CommandResult(2);
        }

        $report = $this->doctor->run();

        foreach ($report->findings() as $finding) {
            $this->writeFinding($finding, $output);
        }

        return new CommandResult($report->successful() ? 0 : 1);
    }

    private function writeFinding(DoctorFinding $finding, CommandOutput $output): void
    {
        $output->write(sprintf(
            '[%s] %s: %s',
            strtoupper($finding->status()->value),
            $finding->identifier(),
            $finding->message(),
        ));

        if ($finding->remediation() !== null) {
            $output->write(sprintf('Remediation: %s', $finding->remediation()));
        }
    }
}
