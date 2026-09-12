<?php

declare(strict_types=1);

namespace Evolve\Bridge\LegacyHttp;

final class LegacyRemoteTransportResponse
{
    /** @var int */
    private $status;
    /** @var array<string, list<string>> */
    private $headers;
    /** @var string */
    private $body;

    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(int $status, array $headers, string $body)
    {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException('Remote Bridge transport status is invalid.');
        }

        $this->status = $status;
        $this->headers = $this->normalizeHeaders($headers);
        $this->body = $body;
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, list<string>> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @return list<string> */
    public function header(string $name): array
    {
        $normalized = strtolower((string) $name);

        return $this->headers[$normalized] ?? [];
    }

    public function headerLine(string $name): string
    {
        return implode(', ', $this->header($name));
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @return array<string, list<string>>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $values) {
            if (!is_string($name) || !is_array($values)) {
                throw new \InvalidArgumentException('Remote Bridge transport headers are invalid.');
            }

            $normalized[strtolower($name)] = [];

            foreach ($values as $value) {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException('Remote Bridge transport headers are invalid.');
                }

                $normalized[strtolower($name)][] = $value;
            }
        }

        ksort($normalized);

        return $normalized;
    }
}
