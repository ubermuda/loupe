<?php

declare(strict_types=1);

namespace App\Module\Audit;

/**
 * Marks whatever the consuming application calls a user. Stays empty: a record
 * carries its own display label, so the package never asks an actor for one.
 */
interface AuditActorInterface
{
}
