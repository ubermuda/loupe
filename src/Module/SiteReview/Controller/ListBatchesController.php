<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Repository\SiteReviewBatchRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    '/site-review/batches',
    name: 'app_site_review_batches',
    methods: ['GET'],
)]
class ListBatchesController extends AppController
{
    public function __construct(
        private readonly SiteReviewBatchRepository $siteReviewBatches,
    ) {
    }

    public function __invoke(): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        $batches = $this->siteReviewBatches->findBy(['owner' => $user], ['createdAt' => 'DESC']);

        return $this->render('@SiteReview/batches/list.html.twig', [
            'batches' => $batches,
        ]);
    }
}
