<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Admin;

use App\Controller\AppController;
use App\Module\Account\Repository\WaitlistEntryRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\AdminBundle\Listing\ListPagePagination;
use Ubermuda\AdminBundle\Listing\ListPageRequest;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/waitlist', name: 'app_admin_waitlist_list')]
final class ListWaitlistController extends AppController
{
    private const int PER_PAGE = 20;
    private const array ALLOWED_SORTS = ['email', 'createdAt', 'invitedAt'];

    public function __construct(
        private readonly WaitlistEntryRepository $entries,
        private readonly ListPagePagination $pagination,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $listRequest = ListPageRequest::fromRequest($request, self::ALLOWED_SORTS, 'createdAt', 'asc');

        $paginator = $this->entries->findPaginated(
            page: $listRequest->page,
            perPage: self::PER_PAGE,
            sort: $listRequest->sort,
            dir: $listRequest->dir,
        );
        $total = count($paginator);

        $clampedPage = $this->pagination->clampPage('waitlist_entries', $listRequest->page, $total, self::PER_PAGE, []);
        if (null !== $clampedPage) {
            return $this->redirectToRoute(
                'app_admin_waitlist_list',
                [...$request->query->all(), 'page' => $clampedPage],
            );
        }

        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

        return $this->render('@Account/admin/waitlist.html.twig', [
            'entries' => $paginator,
            'total' => $total,
            'page' => $listRequest->page,
            'totalPages' => $totalPages,
            'pageList' => $this->pagination->buildPageList($listRequest->page, $totalPages),
            'sort' => $listRequest->sort,
            'dir' => $listRequest->dir,
            'filters' => [],
        ]);
    }
}
