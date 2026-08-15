<?php

declare(strict_types=1);

namespace Evolve\Module;

use Evolve\Module\Exception\IncompatibleModuleDescriptor;

/**
 * Validates the bounded framework-major compatibility declared by a module descriptor.
 *
 * @experimental
 */
final class ModuleCompatibilityValidator
{
    private const SUPPORTED_EVOLVE_MAJOR = 2;

    public function validate(ModuleDescriptor $descriptor): void
    {
        if ($descriptor->evolveMajor() === self::SUPPORTED_EVOLVE_MAJOR) {
            return;
        }

        throw new IncompatibleModuleDescriptor(sprintf(
            'Module descriptor "%s" declares incompatible EvolvePHP major %d; supported major is %d.',
            (string) $descriptor->identifier(),
            $descriptor->evolveMajor(),
            self::SUPPORTED_EVOLVE_MAJOR,
        ));
    }
}
