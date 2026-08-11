<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use Evolve\Contracts\Exception\ConfigurationException;
use RuntimeException;

/**
 * @internal Core validation implementation detail; catch ConfigurationException instead.
 */
final class ConfigurationValidationFailed extends RuntimeException implements ConfigurationException {}
