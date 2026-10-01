<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Account\Service\RegistrationGate;
use App\Module\Billing\Repository\BetaInviteRepository;
use App\Module\Billing\Repository\BillingProfileRepository;

/**
 * Reads only. Turbo prefetches a link on hover, so opening the link must not
 * redeem it. ClaimBetaInviteHandler redeems on the POST.
 */
final readonly class OpenBetaInviteHandler
{
    public function __construct(
        private BetaInviteRepository $betaInvites,
        private BillingProfileRepository $billingProfiles,
        private RegistrationGate $registrationGate,
    ) {
    }

    public function __invoke(OpenBetaInviteCommand $command): OpenBetaInviteView
    {
        $user = $command->user;
        $invite = $this->betaInvites->findOneByToken($command->token);

        if (null === $user) {
            if (!($invite?->isUsable() ?? false)) {
                return new OpenBetaInviteView(BetaInviteOutcome::Invalid);
            }

            return new OpenBetaInviteView(
                $this->registrationGate->allowsNewAccounts() ? BetaInviteOutcome::SignUp : BetaInviteOutcome::RegistrationDisabled,
            );
        }

        if (null === $invite) {
            return new OpenBetaInviteView(BetaInviteOutcome::Invalid);
        }

        if ($invite->isRedeemedBy($user)) {
            return new OpenBetaInviteView(
                BetaInviteOutcome::Redeemed,
                $this->billingProfiles->findOneByUser($user)?->hasLiveSubscription() ?? false,
            );
        }

        return new OpenBetaInviteView($invite->isUsable() ? BetaInviteOutcome::Claimable : BetaInviteOutcome::Invalid);
    }
}
