<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller;

use App\Controller\AppController;
use App\Module\Project\Entity\Project;
use App\Module\Project\Security\ProjectVoter;
use App\Module\SiteReview\Repository\SiteReviewRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ProjectVoter::VIEW, subject: 'project')]
#[Route(
    '/site-review/sites/{id:project}',
    name: 'app_site_review_site',
    methods: ['GET'],
)]
class ShowSiteController extends AppController
{
    public function __construct(
        private readonly SiteReviewRepository $siteReviews,
    ) {
    }

    public function __invoke(Project $project): Response
    {
        return $this->render('@SiteReview/sites/show_site.html.twig', [
            'project' => $project,
            'reviews' => $this->siteReviews->findForProject($project),
        ]);
    }
}
