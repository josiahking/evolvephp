<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use Evolve\Contracts\Exception\ConfigurationException;
use InvalidArgumentException;

/**
 * @internal Core configuration implementation detail; catch ConfigurationException instead.
 */
final class InvalidConfiguration extends InvalidArgumentException implements ConfigurationException {}
