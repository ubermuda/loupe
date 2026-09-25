<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Board\Service\FeedbackCardTitle;
use App\Module\SiteReview\Command\CommentNotFound;
use App\Module\SiteReview\Command\DeleteCommentCommand;
use App\Module\SiteReview\Command\DeleteCommentHandler;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Deletes a pending widget note, and the card the note created when nobody
 * has worked on that card since: it still sits in the default column, holds
 * no other note, is not an epic, has an empty body, and has no pull request,
 * no document and no link to or from another card. Returns whether the card
 * went too.
 */
final readonly class DeleteFeedbackHandler
{
    public const string BOARD_DISABLED = AddFeedbackHandler::BOARD_DISABLED;

    public function __construct(
        private DeleteCommentHandler $deleteComment,
        private DeleteCardHandler $deleteCard,
        private SiteReviewCommentRepository $siteReviewComments,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private CardRepository $cards,
        private BoardAvailability $board,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    /** @throws CommentNotFound when the note is not a pending note of the project */
    public function __invoke(DeleteFeedbackCommand $command): bool
    {
        if (!$this->board->isEnabled()) {
            throw new DomainErrors(['board' => self::BOARD_DISABLED]);
        }

        // A refusal leaves the closure as a value, because a throw closes the
        // EntityManager. The project lock orders this against a note that
        // lands on the same card.
        $cardDeleted = $this->em->wrapInTransaction(function () use ($command): bool|CommentNotFound {
            $this->em->lock($command->project, LockMode::PESSIMISTIC_WRITE);

            $comment = $this->siteReviewComments->findOnePending($command->commentId, $command->project);
            if (null === $comment) {
                return CommentNotFound::forId($command->commentId);
            }

            $noteTitle = FeedbackCardTitle::of($comment->body);
            // Removed through the ORM in the comment's flush. The database
            // cascade alone leaves the link managed, and the next flush then
            // finds it pointing at a removed comment.
            $link = $this->cardSiteReviewComments->findOneBy(['comment' => $comment]);
            if (null !== $link) {
                $this->em->remove($link);
            }
            ($this->deleteComment)(new DeleteCommentCommand($command->project, $command->commentId));

            if (null === $link || !$link->createdCard) {
                return false;
            }

            $card = $link->card;
            $this->cards->refreshColumn($card);
            $this->cards->refreshTypeAndParent($card);
            if (!$card->column->isDefault
                || CardType::Epic === $card->type
                || [] !== $this->cardSiteReviewComments->findForCard($card)
                || $this->cards->hasWork($card, $noteTitle, CardType::SiteReview)) {
                return false;
            }

            ($this->deleteCard)(new DeleteCardCommand($card, CardReporter::Reviewer));

            return true;
        });

        if ($cardDeleted instanceof CommentNotFound) {
            throw $cardDeleted;
        }

        $this->auditor->record(
            'board.feedback_deleted',
            AuditOutcome::Success,
            [
                'commentId' => (string) $command->commentId,
                'projectId' => (string) $command->project->id,
                'cardDeleted' => $cardDeleted,
            ],
            new AuditSubject('site_review_comment', (string) $command->commentId),
        );

        return $cardDeleted;
    }
}
