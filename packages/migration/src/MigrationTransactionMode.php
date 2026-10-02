<?php

declare(strict_types=1);

namespace Evolve\Migration;

enum MigrationTransactionMode
{
    case None;
    case Database;
}
