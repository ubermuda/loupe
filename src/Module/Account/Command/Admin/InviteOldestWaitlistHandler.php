<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\WaitlistInviter;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final readonly class InviteOldestWaitlistHandler
{
    public function __construct(
        private WaitlistEntryRepository $entries,
        private WaitlistInviter $inviter,
    ) {
    }

    public function __invoke(InviteOldestWaitlistCommand $command): InviteWaitlistResult
    {
        $count = max(1, min(100, $command->count));

        $invited = 0;
        $skipped = 0;

        foreach ($this->entries->findOldestUninvited($count) as $entry) {
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
