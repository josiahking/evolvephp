<?php

declare(strict_types=1);

namespace Evolve\Core\Doctor;

enum DoctorStatus: string
{
    case Pass = 'pass';
    case Warning = 'warning';
    case Fail = 'fail';
}
