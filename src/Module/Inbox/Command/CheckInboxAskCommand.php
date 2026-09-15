<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Account\Entity\User;
use Symfony\Component\Uid\Uuid;

final readonly class CheckInboxAskCommand
{
    public function __construct(
        public User $owner,
        /** A project id or slug. */
        public string $handle,
        public Uuid $askId,
    ) {
    }
}
