<?php

declare(strict_types=1);

namespace Evolve\Plugin;

use Evolve\Plugin\Exception\IncompatiblePluginDescriptor;

/**
 * Validates the bounded framework-major compatibility declared by a plugin descriptor.
 *
 * @experimental
 */
final class PluginCompatibilityValidator
{
    private const SUPPORTED_EVOLVE_MAJOR = 2;

    public function validate(PluginDescriptor $descriptor): void
    {
        if ($descriptor->evolveMajor() === self::SUPPORTED_EVOLVE_MAJOR) {
            return;
        }

        throw new IncompatiblePluginDescriptor(sprintf(
            'Plugin descriptor "%s" declares incompatible EvolvePHP major %d; supported major is %d.',
            (string) $descriptor->identifier(),
            $descriptor->evolveMajor(),
            self::SUPPORTED_EVOLVE_MAJOR,
        ));
    }
}
