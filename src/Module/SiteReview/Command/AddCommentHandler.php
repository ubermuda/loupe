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
            $this->registry->resetManager();
            $review = $this->siteReviews->findOneInProgress($command->project);
            if (null === $review) {
                // The winner's row is already gone by the time we retry (e.g. it was
                // submitted in between) — the same "no review yet" case handled above,
                // not a second error path. resetManager() detached every entity the old
                // EM knew about, including $command->project, so constructing
                // `new SiteReview($command->project)` here would hand the new EM's
                // UnitOfWork a Project it has never seen; this relation has no
                // cascade=persist, so flush() would throw trying to persist a "new"
                // entity through it. Resolve a managed reference by id instead of
                // reusing the stale object.
                $projectId = $command->project->id ?? throw new \LogicException('a comment\'s project must already be persisted');
                /** @var Project $project */
                $project = $this->em->getReference(Project::class, $projectId);
                $review = new SiteReview($project);
                $this->em->persist($review);
            }
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
