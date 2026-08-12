<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

final class ExecutionScopeUnavailable extends RuntimeException implements ContainerExceptionInterface {}
