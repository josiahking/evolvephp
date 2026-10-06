<?php

declare(strict_types=1);

namespace Evolve\Insight\Dashboard;

use Evolve\Insight\Query\DiagnosticBatchQuery;

final readonly class DashboardQueryParser
{
    private const array ACCEPTED_KEYS = ['page_size', 'cursor', 'execution_kind', 'category', 'name'];

    /** @param array<string, mixed> $parameters */
    public function parse(array $parameters): DiagnosticBatchQuery
    {
        foreach (array_keys($parameters) as $key) {
            if (!in_array($key, self::ACCEPTED_KEYS, true)) {
                throw new \InvalidArgumentException('Unknown dashboard query parameter.');
            }
        }

        $pageSize = $this->value($parameters, 'page_size');
        if ($pageSize !== null && (preg_match('/\A[1-9][0-9]{0,2}\z/D', $pageSize) !== 1 || (int) $pageSize > 100)) {
            throw new \InvalidArgumentException('Invalid dashboard page size.');
        }

        return new DiagnosticBatchQuery(
            $pageSize === null ? 25 : (int) $pageSize,
            $this->value($parameters, 'cursor'),
            $this->value($parameters, 'execution_kind'),
            $this->value($parameters, 'category'),
            $this->value($parameters, 'name'),
        );
    }

    /** @param array<string, mixed> $parameters */
    private function value(array $parameters, string $key): ?string
    {
        if (!array_key_exists($key, $parameters)) {
            return null;
        }

        $value = $parameters[$key];
        if (!is_string($value) || $value === '' || strlen($value) > 512 || trim($value) !== $value || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException('Invalid dashboard query parameter.');
        }

        return $value;
    }
}
