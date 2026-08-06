<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Account\Entity\User;

final readonly class EnsureHarnessProjectCommand
{
    public function __construct(
        public User $owner,
        public string $name,
    ) {
    }
}
