<?php

declare(strict_types=1);

namespace Evolve\Core\Doctor;

interface DoctorCheck
{
    public function identifier(): string;

    public function run(): DoctorFinding;
}
