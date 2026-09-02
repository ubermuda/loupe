<?php

declare(strict_types=1);

namespace App\Module\Audit;

/**
 * How many days the trail keeps a record. The consuming application implements
 * this when the window must be editable at runtime; the package answers from a
 * container parameter when it does not.
 *
 * An implementation returns at least one day. Zero empties the trail on the
 * next sweep, and a negative number makes DateInterval throw.
 */
interface AuditRetentionPolicyInterface
{
    public function retentionDays(): int;
}
