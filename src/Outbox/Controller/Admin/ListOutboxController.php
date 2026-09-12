<?php

declare(strict_types=1);

namespace App\Outbox\Controller\Admin;

use App\Controller\AppController;
use App\Outbox\Command\ListOutboxCommand;
use App\Outbox\Command\ListOutboxHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\AdminBundle\Listing\ListPageRequest;

#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/outbox',
    name: 'app_admin_outbox_list',
    methods: ['GET'],
)]
final class ListOutboxController extends AppController
{
    public function __construct(
        private readonly ListOutboxHandler $listOutbox,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $listRequest = ListPageRequest::fromRequest(
            $request,
            ListOutboxHandler::ALLOWED_SORTS,
            'createdAt',
            'asc',
        );

        $view = ($this->listOutbox)(new ListOutboxCommand(
            page: $listRequest->page,
            sort: $listRequest->sort,
            dir: $listRequest->dir,
            requestedProjectId: $request->query->getString('project'),
        ));

        if (null !== $view->clampedPage) {
            return $this->redirectToRoute(
                'app_admin_outbox_list',
                [...$request->query->all(), 'page' => $view->clampedPage],
            );
        }

        return $this->render('outbox/admin/list_outbox.html.twig', [
            'events' => $view->events,
            'projects' => $view->projects,
            'selectedProjectId' => $view->selectedProjectId,
            'total' => $view->total,
            'page' => $listRequest->page,
            'totalPages' => $view->totalPages,
            'pageList' => $view->pageList,
            'sort' => $listRequest->sort,
            'dir' => $listRequest->dir,
            'filters' => $view->filters,
        ]);
    }
}
