<?php

declare(strict_types=1);

namespace App\Module\Board\Entity;

/** Where the review of one pull request stands after a verdict. */
enum CardVerdictDeliveryState: string
{
    case Pending = 'pending';
    case Posted = 'posted';
    case Commented = 'commented';
    case Skipped = 'skipped';
    case Refused = 'refused';
}
