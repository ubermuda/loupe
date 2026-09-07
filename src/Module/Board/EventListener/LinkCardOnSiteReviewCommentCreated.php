<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\BoardAvailability;
use App\Module\SiteReview\Event\SiteReviewCommentCreated;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

/**
 * Attaches a new site-review comment to the card its page named.
 *
 * Runs inside AddCommentHandler's transaction, so it persists and lets that
 * transaction flush. It must never throw: anything raised here aborts the
 * comment save, and losing a reviewer's words to a board that could not find a
 * card is a worse outcome than an unlinked comment.
 */
#[AsEventListener]
final readonly class LinkCardOnSiteReviewCommentCreated
{
    /** Marks a context naming a card, followed by the card's id. */
    private const string PREFIX = 'card:';

    public function __construct(
        private CardRepository $cards,
        private EntityManagerInterface $em,
        private BoardAvailability $board,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SiteReviewCommentCreated $event): void
    {
        $comment = $event->comment;
        $context = $comment->context;
        if (null === $context || !str_starts_with($context, self::PREFIX)) {
            return;
        }

        // A board switched off writes no rows. Linking to a board nobody can
        // open would leave the operator with data they never asked for, and the
        // links would be invisible until the flag went on.
        if (!$this->board->isEnabled()) {
            return;
        }

        $id = substr($context, \strlen(self::PREFIX));
        if (!Uuid::isValid($id)) {
            $this->logger->info('board.card_link_skipped_malformed_context', [
                'commentId' => (string) $comment->id,
            ]);

            return;
        }

        $card = $this->cards->find(Uuid::fromString($id));
        if (null === $card) {
            $this->logger->info('board.card_link_skipped_unknown_card', [
                'commentId' => (string) $comment->id,
                'cardId' => $id,
            ]);

            return;
        }

        // The context arrives from a page, so a caller chooses it. A widget
        // token is bound to one project, and without this a card in one project
        // would collect comments made on another project's site.
        if ($card->project->id != $comment->project->id) {
            $this->logger->warning('board.card_link_refused_foreign_project', [
                'commentId' => (string) $comment->id,
                'cardId' => $id,
            ]);

            return;
        }

        $this->em->persist(new CardSiteReviewComment($card, $comment));
    }
}
