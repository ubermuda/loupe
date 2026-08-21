<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Module\Account\Entity\User;
use Doctrine\ORM\Tools\Pagination\Paginator;

final readonly class ListUsersView
{
    /**
     * @param Paginator<User>       $users
     * @param list<int|null>        $pageList
     * @param array<string, string> $filters  the active filters, empty values stripped
     */
    public function __construct(
        public Paginator $users,
        public int $total,
        public int $totalPages,
        public array $pageList,
        public array $filters,
        public ?int $clampedPage,
    ) {
    }
}
