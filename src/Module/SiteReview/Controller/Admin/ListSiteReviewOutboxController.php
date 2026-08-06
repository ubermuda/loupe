<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Admin;

use App\Controller\AppController;
use App\Module\SiteReview\Command\ListSiteReviewOutboxCommand;
use App\Module\SiteReview\Command\ListSiteReviewOutboxHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\AdminBundle\Listing\ListPageRequest;

#[IsGranted('ROLE_ADMIN')]
#[Route(
    '/admin/site-review-outbox',
    name: 'app_admin_site_review_outbox_list',
    methods: ['GET'],
)]
final class ListSiteReviewOutboxController extends AppController
{
    public function __construct(
        private readonly ListSiteReviewOutboxHandler $listSiteReviewOutbox,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $listRequest = ListPageRequest::fromRequest(
            $request,
            ListSiteReviewOutboxHandler::ALLOWED_SORTS,
            'createdAt',
            'asc',
        );

        $view = ($this->listSiteReviewOutbox)(new ListSiteReviewOutboxCommand(
            page: $listRequest->page,
            sort: $listRequest->sort,
            dir: $listRequest->dir,
            requestedProjectId: $request->query->getString('project'),
        ));

        if (null !== $view->clampedPage) {
            return $this->redirectToRoute(
                'app_admin_site_review_outbox_list',
                [...$request->query->all(), 'page' => $view->clampedPage],
            );
        }

        return $this->render('@SiteReview/admin/list_site_review_outbox.html.twig', [
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
