<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

/**
 * What a Stripe subscription event turned out to be. The two stale cases share
 * one operation and differ only by the `reason` the caller records, so a reader
 * can tell an out-of-order event from one that arrived after a deletion.
 */
enum StripeSyncDecision
{
    case Applied;
    case StaleEventOlder;
    case StaleEventNotNewerThanDeletion;
    case ReplayedEvent;
    case ConcurrentGrant;
}
