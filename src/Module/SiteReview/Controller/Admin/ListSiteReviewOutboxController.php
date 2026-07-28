<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Admin;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Repository\SiteReviewEventRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Ubermuda\AdminBundle\Listing\ListPagePagination;
use Ubermuda\AdminBundle\Listing\ListPageRequest;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/site-review-outbox', name: 'app_admin_site_review_outbox_list', methods: ['GET'])]
final class ListSiteReviewOutboxController extends AppController
{
    private const int PER_PAGE = 20;
    private const array ALLOWED_SORTS = ['createdAt', 'publishAttempts', 'nextAttemptAt'];

    public function __construct(
        private readonly SiteReviewEventRepository $siteReviewEvents,
        private readonly ListPagePagination $pagination,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $listRequest = ListPageRequest::fromRequest($request, self::ALLOWED_SORTS, 'createdAt', 'asc');

        // Matching the filter against the projects that actually have stuck
        // events, instead of looking the id up, means an unknown or malformed
        // id widens to "all projects" rather than 404-ing or leaking whether a
        // project exists.
        $projectsWithUnsent = $this->siteReviewEvents->findProjectsWithUnsent();
        $requestedProjectId = $request->query->getString('project');
        $project = array_find(
            $projectsWithUnsent,
            static fn (Project $candidate): bool => (string) $candidate->id === $requestedProjectId,
        );

        $filters = null !== $project ? ['project' => $requestedProjectId] : [];

        $total = $this->siteReviewEvents->countUnsent($project);

        $clampedPage = $this->pagination->clampPage(
            'site_review_events',
            $listRequest->page,
            $total,
            self::PER_PAGE,
            $filters,
        );
        if (null !== $clampedPage) {
            return $this->redirectToRoute(
                'app_admin_site_review_outbox_list',
                [...$request->query->all(), 'page' => $clampedPage],
            );
        }

        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

        return $this->render('@SiteReview/admin/list_site_review_outbox.html.twig', [
            'events' => $this->siteReviewEvents->findUnsentPaginated(
                $project,
                $listRequest->page,
                self::PER_PAGE,
                $listRequest->sort,
                $listRequest->dir,
            ),
            'projects' => $projectsWithUnsent,
            'selectedProjectId' => null !== $project ? $requestedProjectId : '',
            'total' => $total,
            'page' => $listRequest->page,
            'totalPages' => $totalPages,
            'pageList' => $this->pagination->buildPageList($listRequest->page, $totalPages),
            'sort' => $listRequest->sort,
            'dir' => $listRequest->dir,
            'filters' => $filters,
        ]);
    }
}
