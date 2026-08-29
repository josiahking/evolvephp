<?php

declare(strict_types=1);

namespace Evolve\Testing\Tests\Unit\Console;

use Evolve\Core\Console\CommandOutput;
use Evolve\Testing\Console\RecordingCommandOutput;
use PHPUnit\Framework\TestCase;

final class RecordingCommandOutputTest extends TestCase
{
    public function testItImplementsCommandOutput(): void
    {
        $interfaces = class_implements(RecordingCommandOutput::class);

        self::assertIsArray($interfaces);
        self::assertContains(CommandOutput::class, $interfaces);
    }

    public function testItStartsEmpty(): void
    {
        $output = new RecordingCommandOutput();

        self::assertSame([], $output->lines());
        self::assertSame([], $output->errorLines());
    }

    public function testItRecordsNormalLinesInOrder(): void
    {
        $output = new RecordingCommandOutput();

        $output->write('first');
        $output->write('second');

        self::assertSame(['first', 'second'], $output->lines());
        self::assertSame([], $output->errorLines());
    }

    public function testItRecordsErrorLinesInOrder(): void
    {
        $output = new RecordingCommandOutput();

        $output->writeError('first error');
        $output->writeError('second error');

        self::assertSame([], $output->lines());
        self::assertSame(['first error', 'second error'], $output->errorLines());
    }
}
