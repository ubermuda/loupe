<?php

declare(strict_types=1);

namespace App\Module\Billing\Command\Admin;

use App\Module\Account\Entity\User;

final readonly class CreateBetaInviteCommand
{
    public function __construct(
        public User $createdBy,
        public ?string $note,
    ) {
    }
}
