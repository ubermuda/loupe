<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

final readonly class ListUsersCommand
{
    public function __construct(
        public int $page,
        public string $sort,
        public string $dir,
        public string $query = '',
        public string $verified = '',
        public string $state = '',
        public string $role = '',
    ) {
    }
}
