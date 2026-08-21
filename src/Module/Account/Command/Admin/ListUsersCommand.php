<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

final readonly class ListUsersCommand
{
    public function __construct(
        public int $page,
        public string $sort,
        public string $dir,
        public ?string $query = null,
        public ?string $verified = null,
        public ?string $state = null,
        public ?string $role = null,
    ) {
    }
}
