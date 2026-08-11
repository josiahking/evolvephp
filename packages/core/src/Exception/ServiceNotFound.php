<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class ServiceNotFound extends RuntimeException implements NotFoundExceptionInterface {}
