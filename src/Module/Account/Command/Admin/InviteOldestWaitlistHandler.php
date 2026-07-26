<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Module\Account\Repository\WaitlistEntryRepository;
use App\Module\Account\Service\WaitlistInviter;

final readonly class InviteOldestWaitlistHandler
{
    public function __construct(
        private WaitlistEntryRepository $waitlistEntries,
        private WaitlistInviter $inviter,
    ) {
    }

    public function __invoke(InviteOldestWaitlistCommand $command): InviteWaitlistResult
    {
        $count = max(1, min(100, $command->count));

        $invited = 0;
        $skipped = 0;

        foreach ($this->waitlistEntries->findOldestUninvited($count) as $entry) {
            $this->inviter->invite($entry) ? ++$invited : ++$skipped;
        }

        return new InviteWaitlistResult($invited, $skipped);
    }
}
