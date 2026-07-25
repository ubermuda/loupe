<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class JoinWaitlistHandler
{
    public function __construct(
        private WaitlistEntryRepository $waitlistEntries,
        private UserRepository $users,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(JoinWaitlistCommand $command): void
    {
        if (null !== $this->waitlistEntries->findOneByEmail($command->email)) {
            $this->logger->info('account.waitlist.duplicate_join', ['email' => $command->email]);

            return;
        }

        // An address that already has an account needs no waitlist row — it
        // would sit there as permanently un-invitable clutter (isInviteTokenValid()
        // has no user to bypass anything for). Silent like the branch above:
        // the join response never reveals whether an address is registered.
        if (null !== $this->users->findOneByEmail($command->email)) {
            $this->logger->info('account.waitlist.join_skipped_existing_account', ['email' => $command->email]);

            return;
        }

        try {
            $this->em->persist(new WaitlistEntry($command->email));
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Two concurrent joins with the same email — the row exists, which
            // is the desired end state; silent like the duplicate branch above.
            return;
        }

        $this->logger->info('account.waitlist.joined', ['email' => $command->email]);
    }
}
