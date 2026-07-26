<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Command;

use App\Module\Project\Entity\Project;
use App\Module\SiteReview\Entity\SiteReview;
use App\Module\SiteReview\Entity\SiteReviewComment;
use App\Module\SiteReview\Repository\SiteReviewRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

final readonly class AddCommentHandler
{
    public function __construct(
        private SiteReviewRepository $siteReviews,
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AddCommentCommand $command): SiteReviewComment
    {
        $review = $this->inProgressReviewOrNew($command->project);

        try {
            $comment = $review->addComment($command->body, $command->selector, $command->text, $command->url);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Two concurrent first-comment posts (double-click, two tabs) both pass the
            // findOneInProgress() check above and race to create the in-progress review;
            // the partial unique index (uniq_site_review_in_progress, one per project)
            // lets only one insert win, and the loser's flush() closes the EM. Reset it —
            // the entity_manager service is lazy, so this reinitializes the same injected
            // instance in place rather than swapping it for a new one — and reattach the
            // comment to the winner's row instead of 500ing and losing the visitor's input.
            // inProgressReviewOrNew() falls back to starting a fresh review if the winner's
            // row is already gone by the time we retry (e.g. submitted in between) — the
            // same "no review yet" case handled above, not a second error path.
            $this->registry->resetManager();
            $review = $this->inProgressReviewOrNew($command->project);
            $comment = $review->addComment($command->body, $command->selector, $command->text, $command->url);
            $this->em->flush();
        }

        $this->logger->info('site_review.comment.added', [
            'projectId' => (string) $command->project->id,
            'reviewId' => (string) $review->id,
            'commentId' => (string) $comment->id,
        ]);

        return $comment;
    }

    private function inProgressReviewOrNew(Project $project): SiteReview
    {
        $review = $this->siteReviews->findOneInProgress($project);
        if (null === $review) {
            $review = new SiteReview($project);
            $this->em->persist($review);
        }

        return $review;
    }
}
