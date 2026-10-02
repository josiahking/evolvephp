<?php

declare(strict_types=1);

namespace Evolve\Migration;

interface MigrationContributor
{
    public function identifier(): string;
    public function owner(): MigrationOwner;
    /** @return iterable<MigrationDefinition> */
    public function definitions(): iterable;
}
