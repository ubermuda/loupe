<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardReporter;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Entity\CardType;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\BoardAvailability;
use App\Module\SiteReview\Command\AddCommentCommand;
use App\Module\SiteReview\Command\AddCommentHandler;
use App\Module\SiteReview\Repository\SiteReviewCommentRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Saves a site-review note together with the card it belongs to, creating
 * that card when the note asks for one. The card, the comment and the link
 * commit in one transaction, so neither exists without the other.
 */
final readonly class AddFeedbackHandler
{
    public const string BOARD_DISABLED = 'board_disabled';
    public const string TARGET_NOT_FOUND = 'target_not_found';
    public const string TARGET_CLOSED = 'target_closed';
    public const string TARGET_NOT_EPIC = 'target_not_epic';

    private const int TITLE_LENGTH = 80;

    public function __construct(
        private CreateCardHandler $createCard,
        private AddCommentHandler $addComment,
        private CardRepository $cards,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private SiteReviewCommentRepository $siteReviewComments,
        private BoardAvailability $board,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(AddFeedbackCommand $command): CardSiteReviewComment
    {
        if (null !== $command->deliveryId && !Uuid::isValid($command->deliveryId)) {
            throw new DomainErrors(['deliveryId' => 'delivery_invalid']);
        }

        $created = false;
        // The sub-handlers open nested transactions, which run as savepoints of
        // this one. Every refusal leaves this closure as a value, because a throw
        // closes the EntityManager, and none can happen once a card exists.
        $link = $this->em->wrapInTransaction(function () use ($command, &$created): CardSiteReviewComment|DomainErrors {
            $this->em->lock($command->project, LockMode::PESSIMISTIC_WRITE);

            $retried = null === $command->deliveryId ? null : $this->siteReviewComments->findOneBy([
                'project' => $command->project,
                'deliveryId' => Uuid::fromString($command->deliveryId),
            ]);
            if (null !== $retried) {
                try {
                    // Called for its payload check: a changed body under a known
                    // delivery id is a conflict, not a retry.
                    ($this->addComment)($this->addCommentCommand($command));
                } catch (DomainErrors $conflict) {
                    return $conflict;
                }

                // A comment saved through the old path has no card to return,
                // and a retry must name the target the first attempt saved to.
                $saved = $this->cardSiteReviewComments->findOneBy(['comment' => $retried]);

                return null !== $saved && self::sameTarget($command, $saved)
                    ? $saved
                    : new DomainErrors(['deliveryId' => 'delivery_conflict']);
            }

            // After the retry lookup, so a note saved before the board went off
            // still answers its retry with the saved item.
            if (!$this->board->isEnabled()) {
                return new DomainErrors(['board' => self::BOARD_DISABLED]);
            }

            $card = $this->targetCard($command);
            if ($card instanceof DomainErrors) {
                return $card;
            }
            $createdCard = null === $card;
            $card ??= ($this->createCard)(new CreateCardCommand(
                project: $command->project,
                title: self::titleOf($command->body),
                body: '',
                type: CardType::SiteReview,
                reporter: CardReporter::Reviewer,
                parentCardId: $command->parentCardId,
            ));

            $comment = ($this->addComment)($this->addCommentCommand($command));
            $link = new CardSiteReviewComment($card, $comment, $createdCard);
            $this->em->persist($link);
            $created = true;

            return $link;
        });

        if ($link instanceof DomainErrors) {
            throw $link;
        }
        if (!$created) {
            return $link;
        }

        $this->auditor->record(
            'board.feedback_added',
            AuditOutcome::Success,
            [
                'cardId' => (string) $link->card->id,
                'commentId' => (string) $link->comment->id,
                'projectId' => (string) $command->project->id,
                'createdCard' => $link->createdCard,
            ],
            new AuditSubject('card', (string) $link->card->id),
        );

        return $link;
    }

    /** The open card the note names, null for a card still to create, or the refusal. */
    private function targetCard(AddFeedbackCommand $command): Card|DomainErrors|null
    {
        $projectId = (string) $command->project->id;

        if (null !== $command->cardId) {
            $card = $this->cards->findOneByIdAndProjectId($command->cardId, $projectId);
            if (null === $card) {
                return new DomainErrors(['target' => self::TARGET_NOT_FOUND]);
            }

            return $card->column->terminal ? new DomainErrors(['target' => self::TARGET_CLOSED]) : $card;
        }

        if (null !== $command->parentCardId) {
            $parent = $this->cards->findOneByIdAndProjectId($command->parentCardId, $projectId);
            if (null === $parent) {
                return new DomainErrors(['target' => self::TARGET_NOT_FOUND]);
            }
            if (CardType::Epic !== $parent->type) {
                return new DomainErrors(['target' => self::TARGET_NOT_EPIC]);
            }
            if ($parent->column->terminal) {
                return new DomainErrors(['target' => self::TARGET_CLOSED]);
            }
        }

        return null;
    }

    private function addCommentCommand(AddFeedbackCommand $command): AddCommentCommand
    {
        return new AddCommentCommand(
            project: $command->project,
            body: $command->body,
            url: $command->url,
            anchors: $command->anchors,
            strokes: $command->strokes,
            context: $command->context,
            deliveryId: $command->deliveryId,
        );
    }

    private static function sameTarget(AddFeedbackCommand $command, CardSiteReviewComment $saved): bool
    {
        if (null !== $command->cardId) {
            return (string) $saved->card->id === strtolower($command->cardId);
        }

        return $saved->createdCard && (string) $saved->card->parent?->id === strtolower((string) $command->parentCardId);
    }

    private static function titleOf(string $body): string
    {
        $lines = preg_split('/\R/u', $body) ?: [$body];
        $line = array_find($lines, static fn (string $line): bool => '' !== trim($line)) ?? $body;

        return trim(mb_substr(trim($line), 0, self::TITLE_LENGTH));
    }
}
