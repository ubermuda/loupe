<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

enum BetaInviteOutcome
{
    /** Unknown, used or revoked. */
    case Invalid;
    /** Usable, and the signed-out visitor may create an account with it. */
    case SignUp;
    /** Sign-up is switched off, so the link leads nowhere. */
    case RegistrationDisabled;
    case Redeemed;
}
