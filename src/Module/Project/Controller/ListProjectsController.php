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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/projects',
    name: 'app_projects',
    methods: ['GET'],
)]
class ListProjectsController extends AppController
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly DocumentRepository $documents,
        private readonly SiteReviewRepository $siteReviews,
        private readonly SiteReviewCommentRepository $siteReviewComments,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(\sprintf('%s reached without an authenticated User (got %s); this route must stay behind the ROLE_USER catch-all.', self::class, get_debug_type($user)));
        }

        $form = $this->createForm(CreateProjectFormType::class, new CreateProjectRequest());

        $items = array_map(
            fn ($project) => new ProjectListItem(
                project: $project,
                documentCount: $this->documents->countByProject($project),
                reviewCount: $this->siteReviews->countForProject($project),
                openCount: $this->siteReviewComments->countOpenForProject($project),
            ),
            $this->projects->findByOwner($user),
        );

        return $this->render('@Project/list_projects.html.twig', [
            'items' => $items,
            'form' => $form->createView(),
        ]);
    }
}
