<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\SiteReviewBatch;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SubmitBatchHandler
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(SubmitBatchCommand $command): SiteReviewBatch
    {
        $batch = new SiteReviewBatch($command->actor);
        foreach ($command->comments as $c) {
            $batch->addComment($c['body'], $c['selector'], $c['text'], $c['url']);
        }

        $this->em->persist($batch);
        $this->em->flush();

        return $batch;
    }
}
