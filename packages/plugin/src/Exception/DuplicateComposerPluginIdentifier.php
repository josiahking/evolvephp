<?php

declare(strict_types=1);

namespace Evolve\Plugin\Exception;

/**
 * Raised when Composer-installed plugin metadata declares the same package twice.
 *
 * @experimental
 */
final class DuplicateComposerPluginIdentifier extends ComposerPluginDiscoveryFailed {}
