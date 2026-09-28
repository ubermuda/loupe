<?php

declare(strict_types=1);

namespace App\Session;

enum SessionLockMode: string
{
    case Locking = 'locking';
    case NonLocking = 'non-locking';
    case ReadOnly = 'read-only';
}
