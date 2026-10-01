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

        /** @var array{?BetaInvite, bool} $result the invite this user holds, and whether this request redeemed it */
        $result = $this->em->wrapInTransaction(function () use ($command, $user): array {
            $invite = $this->betaInvites->findOneByToken($command->token);
            if (null === $invite) {
                return [null, false];
            }

            // The lock serializes two people who hold the same link.
            $this->em->lock($invite, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($invite);

            // A reload of the page that redeemed it. The comp is already granted.
            if (null !== $user->id && true === $invite->redeemedBy?->id?->equals($user->id)) {
                return [$invite, false];
            }

            if (!$invite->isUsable()) {
                return [null, false];
            }

            $invite->redeem($user);

            return [$invite, true];
        });
        [$invite, $redeemedNow] = $result;

        if (null === $invite) {
            return new OpenBetaInviteView(BetaInviteOutcome::Invalid);
        }

        // After the commit, so a failed grant leaves the invite used and the
        // account on its trial, the same as at sign-up.
        if ($redeemedNow) {
            ($this->grantBetaComp)($invite);
        }

        return new OpenBetaInviteView(
            BetaInviteOutcome::Redeemed,
            $this->billingProfiles->findOneByUser($user)?->hasLiveSubscription() ?? false,
        );
    }
}
