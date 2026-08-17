<?php

declare(strict_types=1);

namespace Evolve\Contracts\Component;

/**
 * Declares how many providers may satisfy a required capability.
 *
 * @experimental
 */
enum CapabilityCardinality: string
{
    case ExactlyOne = 'exactly_one';
    case OneOrMore = 'one_or_more';
}
