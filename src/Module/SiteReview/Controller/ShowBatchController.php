<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller;

use App\Controller\AppController;
use App\Module\Account\Entity\User;
use App\Module\SiteReview\Repository\SiteReviewBatchRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route(
    '/site-review/batches/{id}',
    name: 'app_site_review_batch',
    methods: ['GET'],
)]
class ShowBatchController extends AppController
{
    public function __construct(
        private readonly SiteReviewBatchRepository $siteReviewBatches,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        try {
            $batchId = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException();
        }

        $batch = $this->siteReviewBatches->findOneByIdAndOwner($batchId, $user)
            ?? throw $this->createNotFoundException();

        return $this->render('@SiteReview/batches/show.html.twig', [
            'batch' => $batch,
        ]);
    }
}
