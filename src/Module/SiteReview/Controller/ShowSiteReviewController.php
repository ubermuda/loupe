<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\SiteReview\Command\ShowSiteReviewCommand;
use App\Module\SiteReview\Command\ShowSiteReviewHandler;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/projects/{id:project}/site-review',
    name: 'app_project_site_review',
    methods: ['GET'],
)]
class ShowSiteReviewController extends AppController
{
    public function __construct(
        private readonly ShowSiteReviewHandler $showSiteReview,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        $view = ($this->showSiteReview)(new ShowSiteReviewCommand($project));

        return $this->render('@SiteReview/show_site_review.html.twig', [
            'project' => $view->project,
            'comments' => $view->comments,
            'unsentCount' => $view->unsentCount,
        ]);
    }
}
