<?php

declare(strict_types=1);

namespace App\Module\Project\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\Project\Form\CreateProjectFormType;
use App\Module\Project\Form\CreateProjectRequest;
use App\Module\Project\Repository\ProjectRepository;
use App\Module\Project\View\ProjectListItem;
use App\Module\Review\Repository\DocumentRepository;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use App\Module\SiteReview\Repository\SiteReviewRepository;
use App\Utils\PageList;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/projects',
    name: 'app_projects',
    methods: ['GET'],
)]
class ListProjectsController extends AppController
{
    private const int PER_PAGE = 20;

    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly DocumentRepository $documents,
        private readonly SiteReviewRepository $siteReviews,
        private readonly SiteReviewCommentRepository $siteReviewComments,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        $page = max(1, $request->query->getInt('page', 1));

        $paginator = $this->projects->findPaginatedByOwner($user, $page, self::PER_PAGE);
        $total = count($paginator);

        $clampedPage = PageList::clampedPage($page, $total, self::PER_PAGE);
        if (null !== $clampedPage) {
            $this->logger->info('project.list.page_clamped', [
                'user' => (string) $user->id,
                'requestedPage' => $page,
                'clampedPage' => $clampedPage,
            ]);

            return $this->redirectToRoute('app_projects', ['page' => $clampedPage]);
        }

        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));

        $form = $this->createForm(CreateProjectFormType::class, new CreateProjectRequest());

        $items = array_map(
            fn ($project) => new ProjectListItem(
                project: $project,
                documentCount: $this->documents->countByProject($project),
                reviewCount: $this->siteReviews->countForProject($project),
                openCount: $this->siteReviewComments->countOpenForProject($project),
            ),
            iterator_to_array($paginator, false),
        );

        return $this->render('@Project/list_projects.html.twig', [
            'items' => $items,
            'form' => $form->createView(),
            'page' => $page,
            'totalPages' => $totalPages,
            'pageList' => PageList::build($page, $totalPages),
        ]);
    }
}
