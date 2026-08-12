<?php

declare(strict_types=1);

namespace Evolve\Contracts\Tests\Unit\Execution;

use Evolve\Contracts\Execution\ResetParticipant;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

final class ResetParticipantContractTest extends TestCase
{
    public function test_reset_participant_exposes_only_reset(): void
    {
        self::assertTrue(interface_exists(ResetParticipant::class), ResetParticipant::class . ' should exist.');

        $contract = new ReflectionClass(ResetParticipant::class);

        self::assertTrue($contract->isInterface());
        self::assertSame(['reset'], $this->publicMethodNames($contract));

        $reset = $contract->getMethod('reset');

        self::assertSame([], $reset->getParameters());
        $returnType = $reset->getReturnType();

        self::assertInstanceOf(ReflectionNamedType::class, $returnType);
        self::assertSame('void', $returnType->getName());

        foreach (['identifier', 'priority', 'dependencies', 'supports', 'context', 'execution', 'close', 'dispose'] as $method) {
            self::assertFalse($contract->hasMethod($method), ResetParticipant::class . ' must not expose ' . $method . '().');
        }
    }

    /**
     * @template T of object
     *
     * @param ReflectionClass<T> $contract
     *
     * @return list<string>
     */
    private function publicMethodNames(ReflectionClass $contract): array
    {
        $methodNames = array_map(
            static fn(ReflectionMethod $method): string => $method->getName(),
            $contract->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($methodNames);

        return $methodNames;
    }
}
