<?php

declare(strict_types=1);

namespace App\Module\Account\Command\Admin;

use App\Module\Account\Repository\WaitlistEntryRepository;
use Ubermuda\AdminBundle\Listing\ListPagePagination;

final readonly class ListWaitlistHandler
{
    public const int PER_PAGE = 20;
    public const array ALLOWED_SORTS = ['email', 'createdAt', 'invitedAt'];

    public function __construct(
        private WaitlistEntryRepository $waitlistEntries,
        private ListPagePagination $pagination,
    ) {
    }

    public function __invoke(ListWaitlistCommand $command): ListWaitlistView
    {
        $paginator = $this->waitlistEntries->findPaginated(
            page: $command->page,
            perPage: self::PER_PAGE,
            sort: $command->sort,
            dir: $command->dir,
        );
        $total = count($paginator);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

        return new ListWaitlistView(
            entries: $paginator,
            total: $total,
            totalPages: $totalPages,
            pageList: $this->pagination->buildPageList($command->page, $totalPages),
            clampedPage: $this->pagination->clampPage('waitlist_entries', $command->page, $total, self::PER_PAGE, []),
        );
    }
}
