<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\SiteReview\Entity\SiteReview;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class AddCommentHandler
{
    public function __construct(
        private SiteReviewRepository $siteReviews,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AddCommentCommand $command): SiteReviewComment
    {
        $review = $this->siteReviews->findOneInProgress($command->project);
        if (null === $review) {
            $review = new SiteReview($command->project);
            $this->em->persist($review);
        }

        $comment = $review->addComment($command->body, $command->selector, $command->text, $command->url);
        $this->em->flush();

        $this->logger->info('site_review.comment.added', [
            'projectId' => (string) $command->project->id,
            'reviewId' => (string) $review->id,
            'commentId' => (string) $comment->id,
        ]);

        return $comment;
    }
}
