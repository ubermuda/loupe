<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Account\Entity\User;

final readonly class ShowAccountInboxCommand
{
    public function __construct(
        public User $owner,
    ) {
    }
}
