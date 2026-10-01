<?php

declare(strict_types=1);

namespace App\Module\Billing\Registration;

use App\Module\Account\Entity\User;
use App\Module\Account\Registration\RegistrationPassInterface;
use App\Module\Billing\Repository\BetaInviteRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Records nothing in the audit trail: redeem() runs inside the sign-up
 * transaction, so BetaCompGranter records it after commit.
 */
final readonly class BetaRegistrationPass implements RegistrationPassInterface
{
    public function __construct(
        private BetaInviteRepository $betaInvites,
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function isValid(string $token): bool
    {
        return $this->betaInvites->findOneByToken($token)?->isUsable() ?? false;
    }

    #[\Override]
    public function redeem(string $token, User $user): bool
    {
        $invite = $this->betaInvites->findOneByToken($token);
        if (null === $invite || !$invite->isUsable()) {
            return false;
        }

        // A concurrent redemption may have used or revoked it since the lookup.
        $this->em->lock($invite, LockMode::PESSIMISTIC_WRITE);
        $this->em->refresh($invite);

        if (!$invite->matches($token) || !$invite->isUsable()) {
            return false;
        }

        $invite->redeem($user);

        return true;
    }
}
