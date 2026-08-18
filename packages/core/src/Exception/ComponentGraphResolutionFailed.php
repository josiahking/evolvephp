<?php

declare(strict_types=1);

namespace Evolve\Core\Exception;

use RuntimeException;

/**
 * Base failure for Core component graph validation and resolution.
 *
 * @experimental
 */
abstract class ComponentGraphResolutionFailed extends RuntimeException {}
