<?php

declare(strict_types=1);

namespace Evolve\Ai;

use InvalidArgumentException;
use JsonException;

final readonly class ToolCall
{
    /** @var array<array-key, mixed> */
    public array $arguments;

    public string $argumentsJson;

    /** @param array<array-key, mixed> $arguments */
    public function __construct(
        public string $id,
        public string $name,
        array $arguments,
    ) {
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $id) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $name)) {
            throw new InvalidArgumentException('Invalid tool call identity.');
        }
        if (array_is_list($arguments) && $arguments !== []) {
            throw new InvalidArgumentException('Tool arguments must be an object.');
        }
        self::assertJsonValues($arguments);
        try {
            $encoded = json_encode((object) $arguments, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid tool arguments.');
        }
        if (strlen($encoded) > 65536) {
            throw new InvalidArgumentException('Tool arguments exceed the size limit.');
        }
        try {
            $snapshot = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid tool arguments.');
        }
        if (!is_array($snapshot)) {
            throw new InvalidArgumentException('Invalid tool arguments.');
        }
        $this->arguments = $snapshot;
        $this->argumentsJson = $encoded;
    }

    /** @param array<array-key, mixed> $arguments */
    private static function assertJsonValues(array $arguments): void
    {
        $pending = [$arguments];
        $count = 0;
        while ($pending !== []) {
            $value = array_pop($pending);
            if (++$count > 4096) {
                throw new InvalidArgumentException('Invalid tool arguments.');
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    $pending[] = $item;
                }
            } elseif (!is_null($value) && !is_bool($value) && !is_int($value) && !is_string($value) && !(is_float($value) && is_finite($value))) {
                throw new InvalidArgumentException('Invalid tool arguments.');
            }
        }
    }
}
