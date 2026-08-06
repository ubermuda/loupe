<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Account\Entity\User;

final readonly class ShowStreamCredentialsCommand
{
    public function __construct(
        public User $owner,
        public string $handle,
    ) {
    }
}
