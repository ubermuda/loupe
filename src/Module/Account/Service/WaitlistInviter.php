<?php

declare(strict_types=1);

namespace App\Module\Account\Service;

use App\Module\Account\Entity\WaitlistEntry;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

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
        // Lock + refresh + recheck: two concurrent invites (e.g. overlapping
        // oldest-N requests) must not both email; and a mail-transport failure
        // must roll the token state back so the entry stays invitable.
        return $this->em->wrapInTransaction(function () use ($entry): bool {
            $this->em->lock($entry, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($entry);

            if (!$entry->needsInvite()) {
                return false;
            }

            $plainToken = $entry->issueInviteToken();
            $this->em->flush();

            try {
                $this->emailSender->send($entry, $plainToken);
            } catch (TransportExceptionInterface $e) {
                $this->logger->warning('account.waitlist.invite_send_failed', [
                    'entryId' => (string) $entry->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e; // roll the transaction back — token state reverts, entry stays invitable
            }

            $this->logger->info('account.waitlist.invited', ['entryId' => (string) $entry->id]);

            return true;
        });
    }
}
