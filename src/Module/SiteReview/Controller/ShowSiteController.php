<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller;

use App\Controller\AppController;
use App\Module\SiteReview\Entity\Site;
use App\Module\SiteReview\Repository\SiteReviewRepository;
use App\Module\SiteReview\Security\SiteVoter;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(SiteVoter::VIEW, subject: 'site')]
#[Route(
    '/site-review/sites/{id:site}',
    name: 'app_site_review_site',
    methods: ['GET'],
)]
class ShowSiteController extends AppController
{
    public function __construct(
        private readonly SiteReviewRepository $siteReviews,
    ) {
    }

    public function __invoke(Site $site): Response
    {
        return $this->render('@SiteReview/sites/show_site.html.twig', [
            'site' => $site,
            'reviews' => $this->siteReviews->findForSite($site),
        ]);
    }
}
