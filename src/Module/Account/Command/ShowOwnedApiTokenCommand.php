<?php

declare(strict_types=1);

namespace App\Module\Account\Command;

use App\Module\Account\Entity\User;
use Symfony\Component\Uid\Uuid;

final readonly class ShowOwnedApiTokenCommand
{
    public function __construct(
        public Uuid $tokenId,
        public User $owner,
    ) {
    }
}
