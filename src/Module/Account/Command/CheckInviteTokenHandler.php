<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Repository\WaitlistEntryRepository;

final readonly class CheckInviteTokenHandler
{
    public function __construct(
        private WaitlistEntryRepository $waitlistEntries,
    ) {
    }

    public function __invoke(CheckInviteTokenCommand $command): CheckInviteTokenView
    {
        return new CheckInviteTokenView(
            valid: null !== $this->waitlistEntries->findOneByValidInviteToken($command->token),
        );
    }
}
