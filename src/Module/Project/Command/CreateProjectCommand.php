<?php

declare(strict_types=1);

namespace App\Module\Project\Command;

use App\Module\Account\Entity\User;

final readonly class CreateProjectCommand
{
    public function __construct(
        public User $owner,
        /** @phpstan-var non-empty-string */
        public string $name,
        public ?string $domain,
    ) {
    }
}
