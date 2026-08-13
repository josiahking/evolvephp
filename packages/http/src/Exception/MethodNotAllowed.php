<?php

declare(strict_types=1);

namespace Evolve\Http\Exception;

use InvalidArgumentException;
use RuntimeException;

final class MethodNotAllowed extends RuntimeException
{
    /**
     * @var list<string>
     */
    private array $allowedMethods;

    /**
     * @param array<mixed, mixed> $allowedMethods
     */
    public function __construct(array $allowedMethods)
    {
        if (!array_is_list($allowedMethods)) {
            throw new InvalidArgumentException('Allowed methods must be a list.');
        }

        if ($allowedMethods === []) {
            throw new InvalidArgumentException('Allowed methods must not be empty.');
        }

        $seenMethods = [];

        foreach ($allowedMethods as $method) {
            if (!is_string($method)) {
                throw new InvalidArgumentException('Allowed methods must be strings.');
            }

            if ($method === '') {
                throw new InvalidArgumentException('Allowed methods must not be empty strings.');
            }

            if (isset($seenMethods[$method])) {
                throw new InvalidArgumentException('Allowed methods must not contain duplicates.');
            }

            $seenMethods[$method] = true;
        }

        $this->allowedMethods = $allowedMethods;

        parent::__construct('The request method is not allowed for the matched route path.');
    }

    /**
     * @return list<string>
     */
    public function allowedMethods(): array
    {
        return $this->allowedMethods;
    }
}
