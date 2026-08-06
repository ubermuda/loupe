<?php

declare(strict_types=1);

namespace App\Module\Account\Controller\Admin;

use App\Controller\AppController;
use App\Module\Account\Command\Admin\ListWaitlistCommand;
use App\Module\Account\Command\Admin\ListWaitlistHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\AdminBundle\Listing\ListPageRequest;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/waitlist', name: 'app_admin_waitlist_list')]
final class ListWaitlistController extends AppController
{
    public function __construct(
        private readonly ListWaitlistHandler $listWaitlist,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $listRequest = ListPageRequest::fromRequest(
            $request,
            ListWaitlistHandler::ALLOWED_SORTS,
            'createdAt',
            'asc',
        );

        $view = ($this->listWaitlist)(new ListWaitlistCommand(
            page: $listRequest->page,
            sort: $listRequest->sort,
            dir: $listRequest->dir,
        ));

        if (null !== $view->clampedPage) {
            return $this->redirectToRoute(
                'app_admin_waitlist_list',
                [...$request->query->all(), 'page' => $view->clampedPage],
            );
        }

        return $this->render('@Account/admin/waitlist.html.twig', [
            'entries' => $view->entries,
            'total' => $view->total,
            'page' => $listRequest->page,
            'totalPages' => $view->totalPages,
            'pageList' => $view->pageList,
            'sort' => $listRequest->sort,
            'dir' => $listRequest->dir,
            'filters' => [],
        ]);
    }
}
