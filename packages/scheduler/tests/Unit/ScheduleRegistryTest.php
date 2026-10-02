<?php

declare(strict_types=1);

namespace Evolve\Scheduler\Tests\Unit;

use Evolve\Scheduler\CronSchedule;
use Evolve\Scheduler\ScheduleContributor;
use Evolve\Scheduler\ScheduledAction;
use Evolve\Scheduler\ScheduleDefinition;
use Evolve\Scheduler\ScheduleRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ScheduleRegistryTest extends TestCase
{
    public function test_single_segment_schedule_identifiers_are_accepted(): void
    {
        self::assertSame('maintenance', $this->definition('maintenance')->identifier());
        self::assertSame('scheduler', $this->definition('scheduler')->identifier());
    }

    public function test_single_segment_contributor_identifier_is_accepted(): void
    {
        $registry = ScheduleRegistry::fromContributors([
            $this->contributor('source', [
                $this->definition('billing.invoice-reminder'),
                $this->definition('maintenance.cache_cleanup'),
                $this->definition('reports.daily'),
            ]),
        ]);

        self::assertSame(
            ['billing.invoice-reminder', 'maintenance.cache_cleanup', 'reports.daily'],
            array_map(static fn(ScheduleDefinition $definition): string => $definition->identifier(), $registry->definitions()),
        );
    }

    public function test_contributor_and_schedule_order_is_stable(): void
    {
        $first = ScheduleRegistry::fromContributors([$this->contributor('source.z', [$this->definition('task.z')]), $this->contributor('source.a', [$this->definition('task.a')])]);
        $second = ScheduleRegistry::fromContributors([$this->contributor('source.a', [$this->definition('task.a')]), $this->contributor('source.z', [$this->definition('task.z')])]);
        self::assertSame(['task.a', 'task.z'], array_map(static fn(ScheduleDefinition $definition): string => $definition->identifier(), $first->definitions()));
        self::assertSame(array_map(static fn(ScheduleDefinition $definition): string => $definition->identifier(), $first->definitions()), array_map(static fn(ScheduleDefinition $definition): string => $definition->identifier(), $second->definitions()));
    }

    public function test_duplicate_contributors_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScheduleRegistry::fromContributors([$this->contributor('source.a', []), $this->contributor('source.a', [])]);
    }

    public function test_duplicate_schedules_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScheduleRegistry::fromContributors([$this->contributor('source.a', [$this->definition('task.a')]), $this->contributor('source.b', [$this->definition('task.a')])]);
    }

    public function test_malformed_identifiers_and_contributors_are_rejected(): void
    {
        foreach (['', 'One', 'One.Two', 'one two', '.one', 'one.', 'one..two'] as $identifier) {
            try {
                $this->definition($identifier);
                self::fail('Malformed schedule identifier accepted.');
            } catch (InvalidArgumentException) {
            }
        }
        $this->expectException(InvalidArgumentException::class);
        ScheduleRegistry::fromContributors([$this->contributor('Bad Contributor', [])]);
    }

    public function test_invalid_contributor_object_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScheduleRegistry::fromContributors([new \stdClass()]);
    }

    public function test_invalid_explicit_timezone_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScheduleDefinition('task.one', new CronSchedule('* * * * *'), ScheduledAction::callback(static fn(): null => null), 'No/Such_Zone');
    }

    /** @param list<ScheduleDefinition> $definitions */
    private function contributor(string $identifier, array $definitions): ScheduleContributor
    {
        return new class ($identifier, $definitions) implements ScheduleContributor {
            /** @param list<ScheduleDefinition> $definitions */
            public function __construct(private string $identifier, private array $definitions) {}
            public function identifier(): string
            {
                return $this->identifier;
            }
            public function definitions(): iterable
            {
                return $this->definitions;
            }
        };
    }

    private function definition(string $identifier): ScheduleDefinition
    {
        return new ScheduleDefinition($identifier, new CronSchedule('* * * * *'), ScheduledAction::callback(static fn(): null => null));
    }
}
