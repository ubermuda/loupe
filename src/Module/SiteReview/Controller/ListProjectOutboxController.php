<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\SiteReview\Repository\SiteReviewEventRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/site-review/outbox',
    name: 'app_project_site_review_outbox',
    methods: ['GET'],
)]
class ListProjectOutboxController extends AppController
{
    public function __construct(
        private readonly SiteReviewEventRepository $siteReviewEvents,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        return $this->render('@SiteReview/list_project_outbox.html.twig', [
            'project' => $project,
            'events' => $this->siteReviewEvents->findUnsentForProject($project),
        ]);
    }
}
