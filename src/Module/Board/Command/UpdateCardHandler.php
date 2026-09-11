<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Event\CardMoved;
use App\Module\Board\Repository\CardRepository;
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
    public function __construct(
        private CardRepository $cards,
        private CardMover $mover,
        private PullRequestUrlResolver $pullRequests,
        private DocumentLinkResolver $documentLinks,
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

        // One write for the whole update, and one lock. A status or priority
        // change is a move, which renumbers a group and decides the completion
        // timestamp, so this handler owns the transaction the move runs in.
        // Flushing the fields first would commit half an update whose move
        // then failed.
        $outcome = $this->em->wrapInTransaction(function () use ($command, $card, $title, $documents): UpdateCardOutcome {
            $this->em->lock($card->project, LockMode::PESSIMISTIC_WRITE);
            // lock() takes the project row and leaves the loaded card as the
            // request read it, which may be before the caller ahead of us in
            // the queue committed. Both the decision below and the move it
            // makes read the group, so both need the group as it is now.
            $this->cards->refreshGroup($card);

            $status = $command->status ?? $card->status;
            $priority = $command->priority ?? $card->priority;
            // A rank is a move of its own: a card dropped elsewhere in the
            // column it already sits in changes neither status nor priority.
            $move = $status !== $card->status || $priority !== $card->priority || null !== $command->position
                ? $this->mover->move($card, $status, $priority, $command->position)
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
                $this->events->dispatch(new CardMoved($card, $move));
            }

            return new UpdateCardOutcome($move, $titleChanged, $bodyChanged, $typeChanged);
        });

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
            || null !== $command->documentIds;

        if (!$changedSomething) {
            return $card;
        }

        // `moved` names the paired board.card_moved record, which holds the
        // status and priority this one does not.
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
                'moved' => null !== $outcome->move,
            ],
            new AuditSubject('card', (string) $card->id),
        );

        return $card;
    }
}
