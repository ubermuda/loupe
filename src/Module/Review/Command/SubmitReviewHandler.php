<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\DocumentStatus;
use App\Module\Review\Entity\Review;
use App\Module\Review\Entity\Verdict;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SubmitReviewHandler
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(SubmitReviewCommand $command): Review
    {
        $document = $command->document;
        $currentVersion = $document->currentVersion();

        $review = new Review(
            version: $currentVersion,
            verdict: $command->verdict,
            reviewer: $command->reviewer,
        );

        $document->status = match ($command->verdict) {
            Verdict::Approved => DocumentStatus::Approved,
            Verdict::ChangesRequested => DocumentStatus::ChangesRequested,
        };

        $this->em->persist($review);
        $this->em->flush();

        return $review;
    }
}
