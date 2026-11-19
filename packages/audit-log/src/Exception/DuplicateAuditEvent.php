<?php

declare(strict_types=1);

namespace Evolve\AuditLog\Exception;

use RuntimeException;

final class DuplicateAuditEvent extends RuntimeException {}