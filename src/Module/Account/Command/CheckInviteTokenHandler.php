<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Registration\RegistrationPasses;

final readonly class CheckInviteTokenHandler
{
    public function __construct(
        private RegistrationPasses $registrationPasses,
    ) {
    }

    public function __invoke(CheckInviteTokenCommand $command): CheckInviteTokenView
    {
        return new CheckInviteTokenView(
            valid: $this->registrationPasses->isValid($command->token),
        );
    }
}
