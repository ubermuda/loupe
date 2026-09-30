<?php

declare(strict_types=1);

namespace App\Module\Billing\Command;

use App\Module\Account\Service\RegistrationGate;
use App\Module\Billing\Entity\BetaInvite;
use App\Module\Billing\Repository\BetaInviteRepository;
use App\Module\Billing\Repository\BillingProfileRepository;
use App\Module\Billing\Service\BetaCompGranter;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class OpenBetaInviteHandler
{
    public function __construct(
        private BetaInviteRepository $betaInvites,
        private BillingProfileRepository $billingProfiles,
        private RegistrationGate $registrationGate,
        private BetaCompGranter $grantBetaComp,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(OpenBetaInviteCommand $command): OpenBetaInviteView
    {
        $user = $command->user;

        if (null === $user) {
            if (!($this->betaInvites->findOneByToken($command->token)?->isUsable() ?? false)) {
                return new OpenBetaInviteView(BetaInviteOutcome::Invalid);
            }

            return new OpenBetaInviteView(
                $this->registrationGate->allowsNewAccounts() ? BetaInviteOutcome::SignUp : BetaInviteOutcome::RegistrationDisabled,
            );
        }

        $invite = $this->em->wrapInTransaction(function () use ($command, $user): ?BetaInvite {
            $invite = $this->betaInvites->findOneByToken($command->token);
            if (null === $invite) {
                return null;
            }

            // The lock serializes two people who hold the same link.
            $this->em->lock($invite, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($invite);

            if (!$invite->isUsable()) {
                return null;
            }

            $invite->redeem($user);

            return $invite;
        });

        if (null === $invite) {
            return new OpenBetaInviteView(BetaInviteOutcome::Invalid);
        }

        // After the commit, so a failed grant leaves the invite used and the
        // account on its trial, the same as at sign-up.
        ($this->grantBetaComp)($invite);

        return new OpenBetaInviteView(
            BetaInviteOutcome::Redeemed,
            $this->billingProfiles->findOneByUser($user)?->hasLiveSubscription() ?? false,
        );
    }
}
