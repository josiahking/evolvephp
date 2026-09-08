<?php

declare(strict_types=1);

namespace Evolve\DevTools\Audit\Console;

use Evolve\Core\Console\Command;
use Evolve\Core\Console\CommandInput;
use Evolve\Core\Console\CommandOutput;
use Evolve\Core\Console\CommandResult;
use Evolve\DevTools\Audit\AuditRunner;
use Evolve\DevTools\Audit\Presentation\AuditReportRenderer;
use InvalidArgumentException;

/**
 * @experimental
 */
final readonly class AuditCommand implements Command
{
    private const USAGE = 'Usage: evolve-audit <target-root> [--format=text|--format=json]';

    public function __construct(
        private AuditRunner $audit,
        private AuditReportRenderer $renderer,
    ) {}

    public function name(): string
    {
        return 'audit';
    }

    public function description(): string
    {
        return 'Run the experimental EvolvePHP project Audit.';
    }

    public function execute(CommandInput $input, CommandOutput $output): CommandResult
    {
        $parsed = $this->parse($input->tokens());

        if ($parsed === null) {
            $output->writeError(self::USAGE);

            return new CommandResult(2);
        }

        try {
            $report = $this->audit->inspect($parsed['targetRoot']);
        } catch (InvalidArgumentException $exception) {
            if ($exception->getMessage() !== 'Audit target root must be an existing directory.') {
                throw $exception;
            }

            $output->writeError($exception->getMessage());

            return new CommandResult(2);
        }

        $output->write($parsed['format'] === 'json'
            ? $this->renderer->renderJson($report)
            : $this->renderer->renderText($report));

        return new CommandResult(0);
    }

    /**
     * @param list<string> $tokens
     * @return array{targetRoot: string, format: 'json'|'text'}|null
     */
    private function parse(array $tokens): ?array
    {
        $format = 'text';
        $formatSeen = false;
        $targets = [];

        foreach ($tokens as $token) {
            if (str_starts_with($token, '--')) {
                if (! str_starts_with($token, '--format=')) {
                    return null;
                }

                if ($formatSeen) {
                    return null;
                }

                $formatValue = substr($token, strlen('--format='));

                if ($formatValue !== 'text' && $formatValue !== 'json') {
                    return null;
                }

                $formatSeen = true;
                $format = $formatValue;

                continue;
            }

            $targets[] = $token;
        }

        if (count($targets) !== 1) {
            return null;
        }

        return [
            'targetRoot' => $targets[0],
            'format' => $format,
        ];
    }
}
