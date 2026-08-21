<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Module\Account\Repository\UserRepository;
use Ubermuda\AdminBundle\Listing\ListPagePagination;

final readonly class ListUsersHandler
{
    public const int PER_PAGE = 20;
    public const array ALLOWED_SORTS = ['fullName', 'email', 'createdAt'];

    private const array ALLOWED_VERIFIED = ['yes', 'no'];
    private const array ALLOWED_STATES = ['active', 'suspended', 'disabled'];
    private const array ALLOWED_ROLES = ['admin', 'user'];

    public function __construct(
        private UserRepository $users,
        private ListPagePagination $pagination,
    ) {
    }

    public function __invoke(ListUsersCommand $command): ListUsersView
    {
        // null means "not filtering"; an allowlist miss collapses to the same
        // thing, so a rejected value can never reach the query.
        $filters = array_filter([
            'q' => trim($command->query ?? '') ?: null,
            'verified' => in_array($command->verified, self::ALLOWED_VERIFIED, true) ? $command->verified : null,
            'state' => in_array($command->state, self::ALLOWED_STATES, true) ? $command->state : null,
            'role' => in_array($command->role, self::ALLOWED_ROLES, true) ? $command->role : null,
        ], static fn (?string $value): bool => null !== $value);

        $paginator = $this->users->findPaginatedForAdmin(
            page: $command->page,
            perPage: self::PER_PAGE,
            sort: $command->sort,
            dir: $command->dir,
            filters: $filters,
        );
        $total = count($paginator);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

        return new ListUsersView(
            users: $paginator,
            total: $total,
            totalPages: $totalPages,
            pageList: $this->pagination->buildPageList($command->page, $totalPages),
            filters: $filters,
            clampedPage: $this->pagination->clampPage('users', $command->page, $total, self::PER_PAGE, $filters),
        );
    }
}
