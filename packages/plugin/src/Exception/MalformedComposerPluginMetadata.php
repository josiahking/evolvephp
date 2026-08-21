<?php

declare(strict_types=1);

namespace Evolve\Plugin\Exception;

/**
 * Raised when Composer-installed plugin metadata is structurally invalid.
 *
 * @experimental
 */
final class MalformedComposerPluginMetadata extends ComposerPluginDiscoveryFailed {}
