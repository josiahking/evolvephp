<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use InvalidArgumentException;
use Psr\Container\ContainerExceptionInterface;

final class InvalidServiceDefinition extends InvalidArgumentException implements ContainerExceptionInterface {}
