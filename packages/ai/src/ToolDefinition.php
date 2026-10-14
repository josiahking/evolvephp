<?php

declare(strict_types=1);

namespace Evolve\Ai;

use InvalidArgumentException;
use JsonException;

final readonly class ToolDefinition
{
    /** @var array<array-key, mixed> */
    public array $inputSchema;

    public string $inputSchemaJson;

    /** @param array<array-key, mixed> $inputSchema */
    public function __construct(
        public string $name,
        public string $description,
        array $inputSchema,
    ) {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/D', $name)) {
            throw new InvalidArgumentException('Invalid tool name.');
        }
        if (trim($description) === '' || strlen($description) > 1024 || preg_match('//u', $description) !== 1) {
            throw new InvalidArgumentException('Invalid tool description.');
        }
        if (($inputSchema['type'] ?? null) !== 'object') {
            throw new InvalidArgumentException('Tool input schema must describe an object.');
        }
        self::assertProperties($inputSchema);
        self::assertJsonValues($inputSchema);
        try {
            json_encode($inputSchema, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid tool input schema.');
        }
        $encoded = json_encode(self::schemaObject($inputSchema), JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 65536) {
            throw new InvalidArgumentException('Tool input schema exceeds the size limit.');
        }
        try {
            $snapshot = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Invalid tool input schema.');
        }
        if (!is_array($snapshot)) {
            throw new InvalidArgumentException('Invalid tool input schema.');
        }
        $this->inputSchema = $snapshot;
        $this->inputSchemaJson = $encoded;
    }

    /** @param array<array-key, mixed> $schema */
    private static function assertProperties(array $schema): void
    {
        if (!isset($schema['properties'])) {
            return;
        }
        $properties = $schema['properties'];
        if (!is_array($properties) || (array_is_list($properties) && $properties !== [])) {
            throw new InvalidArgumentException('Invalid tool input schema properties.');
        }
        foreach ($properties as $propertyName => $propertySchema) {
            if (!is_string($propertyName) || $propertyName === '' || !is_array($propertySchema)) {
                throw new InvalidArgumentException('Invalid tool input schema properties.');
            }
        }
    }

    /** @param array<array-key, mixed> $schema */
    private static function schemaObject(array $schema): object
    {
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $properties = [];
            foreach ($schema['properties'] as $name => $propertySchema) {
                if (!is_string($name) || $name === '' || !is_array($propertySchema)) {
                    throw new InvalidArgumentException('Invalid tool input schema properties.');
                }
                $properties[$name] = self::schemaObject($propertySchema);
            }
            $schema['properties'] = (object) $properties;
        }

        return (object) $schema;
    }

    /** @param array<array-key, mixed> $schema */
    private static function assertJsonValues(array $schema): void
    {
        $pending = [$schema];
        $count = 0;
        while ($pending !== []) {
            $value = array_pop($pending);
            if (++$count > 4096) {
                throw new InvalidArgumentException('Invalid tool input schema.');
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    $pending[] = $item;
                }
            } elseif (!is_null($value) && !is_bool($value) && !is_int($value) && !is_string($value) && !(is_float($value) && is_finite($value))) {
                throw new InvalidArgumentException('Invalid tool input schema.');
            }
        }
    }
}
