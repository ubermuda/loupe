<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\WaitlistEntry;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class WaitlistInviter
{
    public function __construct(
        private WaitlistInviteEmailSender $emailSender,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function invite(WaitlistEntry $entry): bool
    {
        // Issue and commit the token in its own short DBAL-level transaction —
        // not EntityManager::wrapInTransaction(), which closes the shared
        // EntityManager on any failure and would break every remaining entry
        // in a bulk invite. Lock + refresh + recheck: two concurrent invites
        // (e.g. overlapping oldest-N requests) must not both email the same
        // entry.
        $plainToken = $this->em->getConnection()->transactional(function () use ($entry): ?string {
            $this->em->lock($entry, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($entry);

            if (!$entry->needsInvite()) {
                return null;
            }

            $token = $entry->issueInviteToken();
            $this->em->flush();

            return $token;
        });

        if (!is_string($plainToken)) {
            return false;
        }

        $this->emailSender->send($entry, $plainToken);

        $this->logger->info('account.waitlist.invited', ['entryId' => (string) $entry->id]);

        return true;
    }
}
