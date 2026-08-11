<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use LogicException;
use Psr\Container\ContainerExceptionInterface;

final class ServiceRegistryFrozen extends LogicException implements ContainerExceptionInterface {}
