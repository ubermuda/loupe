<?php

declare(strict_types=1);

namespace App\Module\Forge\Command;

/** What the receiver made of one delivery, for the caller to answer with. */
enum ForgeDeliveryOutcome
{
    case UnknownForge;
    case InvalidSignature;
    case Received;
}
