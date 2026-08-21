<?php

declare(strict_types=1);

namespace Evolve\Plugin\Exception;

use RuntimeException;

/**
 * Base exception for Composer-installed plugin metadata discovery failures.
 *
 * @experimental
 */
abstract class ComposerPluginDiscoveryFailed extends RuntimeException {}
