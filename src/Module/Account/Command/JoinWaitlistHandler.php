<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class JoinWaitlistHandler
{
    public function __construct(
        private WaitlistEntryRepository $waitlistEntries,
        private UserRepository $users,
        private EntityManagerInterface $em,
        private Auditor $auditor,
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

                    $this->record('account.waitlist_rejoined', $existingEntry);

                    return;
                }
            }

            $this->record('account.waitlist_duplicate_join', $existingEntry, AuditOutcome::Unchanged);

            return;
        }

        // An ENABLED account needs no waitlist row; a DISABLED one is the
        // opposite, since the waitlist is how it re-enters once the cap has room.
        // Both branches stay silent, so the response never reveals whether an
        // address is registered.
        $existingUser = $this->users->findOneByEmail($command->email);
        if (null !== $existingUser && !$existingUser->isDisabled()) {
            // Named by the account that made this path fire. A digest of the
            // address would do the same correlating job while staying guessable
            // from a wordlist, which is the thing being avoided here.
            $userId = (string) ($existingUser->id ?? throw new \LogicException('A persisted user always has an id.'));
            $this->auditor->record(
                'account.waitlist_join_skipped_existing_account',
                AuditOutcome::Unchanged,
                ['userId' => $userId],
                new AuditSubject('user', $userId),
            );

            return;
        }

        $entry = new WaitlistEntry($command->email);

        try {
            $this->em->persist($entry);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Two concurrent joins with the same email — the row exists, which
            // is the desired end state; silent like the duplicate branch above.
            return;
        }

        $this->record('account.waitlist_joined', $entry);
    }

    private function record(string $operation, WaitlistEntry $entry, AuditOutcome $outcome = AuditOutcome::Success): void
    {
        $entryId = self::entryId($entry);

        $this->auditor->record(
            $operation,
            $outcome,
            ['entryId' => $entryId],
            new AuditSubject('waitlist_entry', $entryId),
        );
    }

    private static function entryId(WaitlistEntry $entry): string
    {
        return (string) ($entry->id ?? throw new \LogicException('a persisted waitlist entry always has an id'));
    }
}
