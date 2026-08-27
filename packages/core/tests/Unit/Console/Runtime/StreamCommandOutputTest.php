<?php

declare(strict_types=1);

namespace Evolve\Core\Tests\Unit\Console\Runtime;

use Evolve\Core\Console\Runtime\StreamCommandOutput;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class StreamCommandOutputTest extends TestCase
{
    public function testWriteWritesToNormalStreamOnly(): void
    {
        $normal = fopen('php://temp', 'w+');
        $error = fopen('php://temp', 'w+');
        $output = new StreamCommandOutput($normal, $error);

        $output->write('hello');

        self::assertSame('hello' . PHP_EOL, $this->contents($normal));
        self::assertSame('', $this->contents($error));
    }

    public function testWriteErrorWritesToErrorStreamOnly(): void
    {
        $normal = fopen('php://temp', 'w+');
        $error = fopen('php://temp', 'w+');
        $output = new StreamCommandOutput($normal, $error);

        $output->writeError('problem');

        self::assertSame('', $this->contents($normal));
        self::assertSame('problem' . PHP_EOL, $this->contents($error));
    }

    public function testWriteAddsExactlyOneLineEnding(): void
    {
        $normal = fopen('php://temp', 'w+');
        $error = fopen('php://temp', 'w+');
        $output = new StreamCommandOutput($normal, $error);

        $output->write('hello');

        self::assertSame('hello' . PHP_EOL, $this->contents($normal));
    }

    public function testWriteErrorAddsExactlyOneLineEnding(): void
    {
        $normal = fopen('php://temp', 'w+');
        $error = fopen('php://temp', 'w+');
        $output = new StreamCommandOutput($normal, $error);

        $output->writeError('problem');

        self::assertSame('problem' . PHP_EOL, $this->contents($error));
    }

    public function testOrderedRepeatedWritesRemainOrdered(): void
    {
        $normal = fopen('php://temp', 'w+');
        $error = fopen('php://temp', 'w+');
        $output = new StreamCommandOutput($normal, $error);

        $output->write('first');
        $output->write('second');
        $output->writeError('third');
        $output->writeError('fourth');

        self::assertSame('first' . PHP_EOL . 'second' . PHP_EOL, $this->contents($normal));
        self::assertSame('third' . PHP_EOL . 'fourth' . PHP_EOL, $this->contents($error));
    }

    public function testInvalidNormalStreamFailsFast(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $class = new ReflectionClass(StreamCommandOutput::class);

        $class->newInstanceArgs([
            'not-a-stream',
            fopen('php://temp', 'w+'),
        ]);
    }

    public function testInvalidErrorStreamFailsFast(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $class = new ReflectionClass(StreamCommandOutput::class);

        $class->newInstanceArgs([
            fopen('php://temp', 'w+'),
            'not-a-stream',
        ]);
    }

    public function testReadOnlyNormalStreamFailsFast(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StreamCommandOutput(fopen(__FILE__, 'r'), fopen('php://temp', 'w+'));
    }

    public function testReadOnlyErrorStreamFailsFast(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StreamCommandOutput(fopen('php://temp', 'w+'), fopen(__FILE__, 'r'));
    }

    public function testInjectedStreamsAreNotClosed(): void
    {
        $normal = fopen('php://temp', 'w+');
        $error = fopen('php://temp', 'w+');
        $output = new StreamCommandOutput($normal, $error);

        $output->write('hello');
        $output->writeError('problem');
        unset($output);

        self::assertTrue(is_resource($normal));
        self::assertTrue(is_resource($error));
    }

    /**
     * @param resource $stream
     */
    private function contents($stream): string
    {
        rewind($stream);

        return stream_get_contents($stream);
    }
}
