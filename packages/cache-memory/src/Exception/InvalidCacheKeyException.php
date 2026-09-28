<?php

declare(strict_types=1);

namespace Evolve\Cache\Memory\Exception;

use InvalidArgumentException;
use Psr\SimpleCache\InvalidArgumentException as PsrInvalidArgumentException;

/**
 * @experimental
 */
final class InvalidCacheKeyException extends InvalidArgumentException implements PsrInvalidArgumentException {}
