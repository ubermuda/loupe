<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\WaitlistEntry;
use App\Module\Account\Repository\UserRepository;
use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Audit\Auditor;
use App\Module\Audit\AuditOutcome;
use App\Module\Audit\AuditSubject;
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

                    $this->record('account.waitlist.rejoined', $existingEntry);

                    return;
                }
            }

            $this->logger->info('account.waitlist.duplicate_join', ['entryId' => self::entryId($existingEntry)]);

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
            $this->logger->info('account.waitlist.join_skipped_existing_account', [
                'userId' => (string) ($existingUser->id ?? throw new \LogicException('A persisted user always has an id.')),
            ]);

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

        $this->record('account.waitlist.joined', $entry);
    }

    private function record(string $operation, WaitlistEntry $entry): void
    {
        $entryId = self::entryId($entry);

        $this->auditor->record(
            $operation,
            AuditOutcome::Success,
            ['entryId' => $entryId],
            new AuditSubject('waitlist_entry', $entryId),
        );
    }

    private static function entryId(WaitlistEntry $entry): string
    {
        return (string) ($entry->id ?? throw new \LogicException('a persisted waitlist entry always has an id'));
    }
}
