<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Event\CardMoved;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardLinkResolver;
use App\Module\Board\Service\CardLinkSync;
use App\Module\Board\Service\CardMover;
use App\Module\Board\Service\CardSearchIndexer;
use App\Module\Board\Service\DocumentLinkResolver;
use App\Module\Board\Service\PullRequestUrlResolver;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** The only handler that moves a card; MoveCardHandler is a shell over it. */
final readonly class UpdateCardHandler
{
    public const string COLUMN_GONE = 'board.card.error.column_gone';
    public const string LINKED_CARD_GONE = 'board.card.error.linked_card_unknown';
    public const string CONTENT_CHANGED = 'board.card.error.changed_since_opened';

    public function __construct(
        private CardRepository $cards,
        private BoardColumnRepository $boardColumns,
        private CardMover $mover,
        private PullRequestUrlResolver $pullRequests,
        private DocumentLinkResolver $documentLinks,
        private CardLinkResolver $cardLinks,
        private CardLinkSync $cardLinkSync,
        private CardSearchIndexer $searchIndexer,
        private EntityManagerInterface $em,
        private Auditor $auditor,
        private EventDispatcherInterface $events,
    ) {
    }

    public function __invoke(UpdateCardCommand $command): Card
    {
        $card = $command->card;

        $title = null;
        if (null !== $command->title) {
            $title = trim($command->title);
            if ('' === $title) {
                throw new DomainErrors(['title' => 'board.card.error.title_blank']);
            }
            if (mb_strlen($title) > Card::MAX_TITLE_LENGTH) {
                throw new DomainErrors(['title' => 'board.card.error.title_too_long']);
            }
        }

        foreach ($command->pullRequestUrls ?? [] as $url) {
            if (mb_strlen(trim($url)) > CardPullRequest::MAX_URL_LENGTH) {
                throw new DomainErrors(['pullRequestUrls' => 'board.card.error.pull_request_url_too_long']);
            }
        }

        // Outside the transaction, for the reason in CreateCardHandler.
        $documents = null === $command->documentIds
            ? null
            : $this->documentLinks->resolve($card->project, array_values($command->documentIds));
        $relatedCards = null === $command->relatedCards
            ? null
            : $this->cardLinks->resolve($card->project, $card, array_values($command->relatedCards));

        // One write for the whole update, and one lock. A column change is a
        // move, which renumbers a column and decides the completion timestamp,
        // so this handler owns the transaction the move runs in.
        // Flushing the fields first would commit half an update whose move
        // then failed.
        $outcome = $this->em->wrapInTransaction(function () use ($command, $card, $title, $documents, $relatedCards): UpdateCardOutcome|string {
            $this->em->lock($card->project, LockMode::PESSIMISTIC_WRITE);
            // lock() takes the project row and leaves the loaded card as the
            // request read it, which may be before the caller ahead of us in
            // the queue committed. Both the decision below and the move it
            // makes read the column, so both need the column as it is now.
            $this->cards->refreshColumn($card);
            // The text too, so the clash check and the change flags below
            // compare against what the last writer committed.
            $this->cards->refreshContent($card);
            if (null !== $command->expectedFingerprint
                && !$command->confirmOverwrite
                && $command->expectedFingerprint !== Card::contentFingerprint($card->title, $card->body)) {
                return self::CONTENT_CHANGED;
            }
            // The columns too: one deleted or given another terminal flag since
            // the request loaded it decides where the card may go and whether
            // the move stamps it.
            $columns = $this->boardColumns->findForProjectFresh($card->project);

            $column = $command->column ?? $card->column;
            if ($column->project !== $card->project) {
                throw new \LogicException('A card moves only to a column of its own board.');
            }
            if (!\in_array($column, $columns, true)) {
                return self::COLUMN_GONE;
            }
            if (null !== $relatedCards && $this->cardLinkSync->anyCardGone($relatedCards)) {
                return self::LINKED_CARD_GONE;
            }
            // A rank is a move of its own: a card dropped elsewhere in the
            // column it already sits in does not change its column.
            $move = $column !== $card->column || null !== $command->position
                ? $this->mover->move($card, $column, $command->position)
                : null;

            // After the move, which must read the card as the database holds
            // it. A field the command carries may hold what the card already
            // holds, so the record reports what changed rather than what was
            // submitted.
            $titleChanged = null !== $title && $title !== $card->title;
            $bodyChanged = null !== $command->body && $command->body !== $card->body;
            $typeChanged = null !== $command->type && $command->type !== $card->type;

            if (null !== $title) {
                $card->title = $title;
            }
            if (null !== $command->body) {
                $card->body = $command->body;
            }
            if (null !== $command->type) {
                $card->type = $command->type;
            }
            if (null !== $documents) {
                $card->syncDocuments(...$documents);
            }
            if (null !== $command->pullRequestUrls) {
                $card->replacePullRequests(...$this->pullRequests->linksFor($card, array_values($command->pullRequestUrls)));
            }
            if (null !== $relatedCards) {
                $this->cardLinkSync->sync($card, $relatedCards);
            }

            $card->updatedAt = new \DateTimeImmutable();
            $this->em->flush();

            // Only the two columns the vector is built from. A move or a link
            // change leaves the searchable text alone, so it costs no reindex.
            if ($titleChanged || $bodyChanged) {
                $this->searchIndexer->index($card);
            }

            // Inside the transaction, so a listener's rows land in the same
            // commit: nothing survives a rollback, and nothing is lost when the
            // process dies after it.
            if (null !== $move) {
                $this->events->dispatch(new CardMoved($card, $move, $command->actor));
            }

            return new UpdateCardOutcome($move, $titleChanged, $bodyChanged, $typeChanged);
        });

        // A refusal leaves the closure as a value, for the reason in AddBoardColumnHandler.
        if (\is_string($outcome)) {
            $field = match ($outcome) {
                self::LINKED_CARD_GONE => 'relatedCards',
                self::CONTENT_CHANGED => 'contentFingerprint',
                default => 'column',
            };
            throw new DomainErrors([$field => $outcome]);
        }

        // After the commit, never inside it: the sink drains at kernel.terminate,
        // so a record written in the closure outlives a rollback. The move comes
        // first, so the pair reads in the order the board applied it.
        if (null !== $outcome->move) {
            $this->auditor->record(
                'board.card_moved',
                AuditOutcome::Success,
                $outcome->move->auditContext($card),
                new AuditSubject('card', (string) $card->id),
            );
        }

        // A replacement is a deliberate act even when the new list matches the
        // old one, so a submitted list counts as a change. A call that only
        // moves the card records the move alone, rather than an update whose
        // every flag is false.
        $changedSomething = $outcome->titleChanged
            || $outcome->bodyChanged
            || $outcome->typeChanged
            || null !== $command->pullRequestUrls
            || null !== $command->documentIds
            || null !== $command->relatedCards;

        if ($changedSomething || null !== $outcome->move) {
            $this->events->dispatch(new CardChanged(
                $card->project->id ?? throw new \LogicException('Project has no id.'),
                $card->id ?? throw new \LogicException('Card has no id.'),
                CardChanged::UPDATED,
                $outcome->titleChanged || $outcome->bodyChanged,
            ));
        }

        if (!$changedSomething) {
            return $card;
        }

        // `moved` names the paired board.card_moved record, which holds the
        // column this one does not.
        $this->auditor->record(
            'board.card_updated',
            AuditOutcome::Success,
            [
                'cardId' => (string) $card->id,
                'cardNumber' => $card->number,
                'projectId' => (string) $card->project->id,
                'titleChanged' => $outcome->titleChanged,
                'bodyChanged' => $outcome->bodyChanged,
                'typeChanged' => $outcome->typeChanged,
                'pullRequestsReplaced' => null !== $command->pullRequestUrls,
                'documentsReplaced' => null !== $command->documentIds,
                'relatedCardsReplaced' => null !== $command->relatedCards,
                'moved' => null !== $outcome->move,
            ],
            new AuditSubject('card', (string) $card->id),
        );

        return $card;
    }
}
