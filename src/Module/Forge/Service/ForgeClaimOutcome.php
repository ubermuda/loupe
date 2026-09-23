<?php

declare(strict_types=1);

namespace App\Module\Forge\Service;

enum ForgeClaimOutcome
{
    /** The claim created the row for this project. */
    case Owned;

    /** This project already held the row. */
    case AlreadyOwned;

    /** An installation claim, and another project holds the installation row. The result names no owner. */
    case Refused;
}
