<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Account\Entity\User;

final readonly class ShowProjectCardCommand
{
    public function __construct(
        public User $owner,
        /** A project id or slug. A project name does not resolve. */
        public string $handle,
        public string $cardId,
    ) {
    }
}
