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
        $existingEntry = $this->waitlistEntries->findOneByEmail($command->email);
        if (null !== $existingEntry) {
            // needsInvite() is permanently false on a CONVERTED row, so a
            // disabled account re-joining would dead-end un-invitable forever;
            // reopen it and queue it at the back for that case alone. Converted
            // is checked first so the frequent pending duplicate skips the user
            // query.
            if (null !== $existingEntry->convertedAt) {
                $existingUser = $this->users->findOneByEmail($command->email);
                if (null !== $existingUser && $existingUser->isDisabled()) {
                    $existingEntry->reopen();
                    $this->em->flush();

                    $this->logger->info('account.waitlist.rejoined', ['email' => $command->email]);

                    return;
                }
            }

            $this->logger->info('account.waitlist.duplicate_join', ['email' => $command->email]);

            return;
        }

        // An ENABLED account needs no waitlist row; a DISABLED one is the
        // opposite, since the waitlist is how it re-enters once the cap has room.
        // Both branches stay silent, so the response never reveals whether an
        // address is registered.
        $existingUser = $this->users->findOneByEmail($command->email);
        if (null !== $existingUser && !$existingUser->isDisabled()) {
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
