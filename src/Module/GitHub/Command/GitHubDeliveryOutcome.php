<?php

declare(strict_types=1);

namespace App\Module\GitHub\Command;

/** What the receiver made of one delivery, for the caller to answer with. */
enum GitHubDeliveryOutcome
{
    case Refused;
    case Received;
}
