<?php

declare(strict_types=1);

namespace Evolve\Module\Exception;

use RuntimeException;

/**
 * Raised when a module descriptor is structurally valid but incompatible with this EvolvePHP major.
 *
 * @experimental
 */
final class IncompatibleModuleDescriptor extends RuntimeException {}
