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
            // Every waitlist-originated account leaves a CONVERTED row behind,
            // and needsInvite() is permanently false on converted rows — so a
            // disabled account re-joining would otherwise dead-end here,
            // un-invitable forever. Re-open the row (and queue it at the back)
            // for exactly that case. Every other duplicate — a live pending
            // row, a converted row whose account is still enabled, or a
            // converted row whose account is gone — keeps the silent skip.
            $existingUser = $this->users->findOneByEmail($command->email);
            if (null !== $existingEntry->convertedAt && null !== $existingUser && $existingUser->isDisabled()) {
                $existingEntry->reopen();
                $this->em->flush();

                $this->logger->info('account.waitlist.rejoined', ['email' => $command->email]);

                return;
            }

            $this->logger->info('account.waitlist.duplicate_join', ['email' => $command->email]);

            return;
        }

        // An address with an ENABLED account needs no waitlist row — it would
        // sit there as permanently un-invitable clutter. A DISABLED account is
        // the opposite: the waitlist is exactly how it re-enters once the cap
        // has room — its email joins like a newcomer's (or re-opens its old
        // converted row, above). Both branches stay silent: the join response
        // never reveals whether an address is registered.
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
