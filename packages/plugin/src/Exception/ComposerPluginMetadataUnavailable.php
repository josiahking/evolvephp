<?php

declare(strict_types=1);

namespace Evolve\Plugin\Exception;

/**
 * Raised when Composer-installed plugin metadata cannot be read.
 *
 * @experimental
 */
final class ComposerPluginMetadataUnavailable extends ComposerPluginDiscoveryFailed {}
