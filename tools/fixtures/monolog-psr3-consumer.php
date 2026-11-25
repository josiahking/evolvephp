<?php

declare(strict_types=1);

use Evolve\Contracts\Execution\ResetParticipant;
use Evolve\Core\Container\ServiceLifetime;
use Evolve\Core\Container\ServiceRegistry;
use Evolve\Core\Execution\ExecutionScope;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Monolog\ResettableInterface;
use Psr\Log\LoggerInterface;

/** Application-owned processor; only its designated extra fields contain automatic correlation. */
final class MonologCorrelationProcessor implements ProcessorInterface, ResettableInterface
{
    private const RESERVED = [
        'trace_id' => true,
        'span_id' => true,
        'trace_flags' => true,
        'evolve.execution.id' => true,
        'evolve.execution.kind' => true,
    ];

    private int $processed = 0;
    private int $resets = 0;

    /** @param (Closure(): array<string, string>)|null $fields */
    public function __construct(private ?Closure $fields) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        ++$this->processed;
        $extra = array_diff_key($record->extra, self::RESERVED);
        $current = $this->fields === null ? [] : ($this->fields)();
        $record->extra = $extra + array_intersect_key($current, self::RESERVED);
        return $record;
    }

    public function reset(): void
    {
        $this->processed = 0;
        ++$this->resets;
    }

    public function processedCount(): int { return $this->processed; }
    public function resetCount(): int { return $this->resets; }
}

/** In-memory test handler; Monolog's parent reset clears its records. */
final class MonologTestHandler extends TestHandler
{
    private int $resets = 0;

    public function __construct(private bool $failReset = false)
    {
        parent::__construct();
    }

    public function reset(): void
    {
        ++$this->resets;
        parent::reset();
        if ($this->failReset) {
            throw new RuntimeException('handler reset failed');
        }
    }

    public function resetCount(): int { return $this->resets; }
}

/** Register before freeze; the application owns channel, handler, processor and policy. */
function registerMonologLogger(
    ServiceRegistry $services,
    ?Closure $correlationFields = null,
    ?Closure $handlerFactory = null,
): void {
    $services->register(LoggerInterface::class, ServiceLifetime::Execution,
        static function (ExecutionScope $scope) use ($correlationFields, $handlerFactory): LoggerInterface {
            $handler = $handlerFactory === null ? new MonologTestHandler() : $handlerFactory();
            $processor = new MonologCorrelationProcessor($correlationFields);
            $logger = new Logger('application', [$handler], [$processor]);
            $scope->registerResetParticipant('application-monolog', new class($logger) implements ResetParticipant {
                public function __construct(private Logger $logger) {}
                public function reset(): void { $this->logger->reset(); }
            });
            return $logger;
        });
}

/** Constructor injection of the PSR-3 contract, independent of Monolog. */
final class MonologGreetingService
{
    public function __construct(private LoggerInterface $logger) {}

    public function greet(string $recipient): void
    {
        $this->logger->info('greeting sent', ['recipient' => $recipient]);
    }
}
