<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\WaitlistInviter;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Uid\Uuid;

final readonly class InviteWaitlistEntriesHandler
{
    public function __construct(
        private WaitlistEntryRepository $entries,
        private WaitlistInviter $inviter,
    ) {
    }

    public function __invoke(InviteWaitlistEntriesCommand $command): InviteWaitlistResult
    {
        $invited = 0;
        $skipped = 0;

        // Dedup + validate: repeated checkboxes and garbage ids count once, as skips.
        $ids = array_values(array_unique(array_filter($command->entryIds, Uuid::isValid(...))));
        $skipped += count(array_unique($command->entryIds)) - count($ids);

        foreach ($ids as $id) {
            $entry = $this->entries->find($id);
            if (null === $entry) {
                ++$skipped;
                continue;
            }

            try {
                $this->inviter->invite($entry) ? ++$invited : ++$skipped;
            } catch (TransportExceptionInterface) {
                // One dead mailbox must not abort the batch; the inviter already
                // rolled this entry back to invitable and logged the failure.
                ++$skipped;
            }
        }

        return new InviteWaitlistResult($invited, $skipped);
    }
}
