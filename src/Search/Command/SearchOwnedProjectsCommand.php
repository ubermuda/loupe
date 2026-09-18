<?php

declare(strict_types=1);

namespace App\Search\Command;

use App\Module\Account\Entity\User;

final readonly class SearchOwnedProjectsCommand
{
    public function __construct(
        public User $owner,
        public string $query = '',
        public int $page = 1,
    ) {
    }
}
