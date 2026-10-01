<?php

declare(strict_types=1);

namespace App\Module\Account\Registration;

use App\Module\Account\Entity\User;
use App\Module\Account\Repository\WaitlistEntryRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class WaitlistRegistrationPass implements RegistrationPassInterface
{
    public function __construct(
        private WaitlistEntryRepository $waitlistEntries,
        private EntityManagerInterface $em,
    ) {
    }

    #[\Override]
    public function isValid(string $token): bool
    {
        return null !== $this->waitlistEntries->findOneByValidInviteToken($token);
    }

    /**
     * The invite binds to the invited address, so a forwarded or leaked link
     * redeems nothing for another address. Refuse before any mutation: the
     * OAuth path commits its transaction on a refusal.
     */
    #[\Override]
    public function redeem(string $token, User $user): bool
    {
        $invite = $this->waitlistEntries->findOneByValidInviteToken($token);
        if (null === $invite) {
            return false;
        }

        // A concurrent redemption may have converted it since the lookup.
        $this->em->lock($invite, LockMode::PESSIMISTIC_WRITE);
        $this->em->refresh($invite);

        if (!$invite->isInviteTokenValid($token) || !$invite->isInviteFor($user->email)) {
            return false;
        }

        $invite->markConverted();

        return true;
    }
}
