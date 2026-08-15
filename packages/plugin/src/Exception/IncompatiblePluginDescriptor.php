<?php

declare(strict_types=1);

namespace Evolve\Plugin\Exception;

use RuntimeException;

/**
 * Raised when a plugin descriptor is structurally valid but incompatible with this EvolvePHP major.
 *
 * @experimental
 */
final class IncompatiblePluginDescriptor extends RuntimeException {}
