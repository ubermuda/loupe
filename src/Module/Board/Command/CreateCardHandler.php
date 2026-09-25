<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardSiteReviewComment;
use App\Module\Board\Event\CardChanged;
use App\Module\Board\Repository\BoardColumnRepository;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Repository\CardSiteReviewCommentRepository;
use App\Module\Board\Service\CardLinkResolver;
use App\Module\Board\Service\CardLinkSync;
use App\Module\Board\Service\CardSearchIndexer;
use App\Module\Board\Service\DocumentLinkResolver;
use App\Module\Board\Service\PullRequestUrlResolver;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class CreateCardHandler
{
    public function __construct(
        private CardRepository $cards,
        private CardSiteReviewCommentRepository $cardSiteReviewComments,
        private BoardColumnRepository $boardColumns,
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

    public function __invoke(CreateCardCommand $command): Card
    {
        $title = trim($command->title);
        if ('' === $title) {
            throw new DomainErrors(['title' => 'board.card.error.title_blank']);
        }
        if (mb_strlen($title) > Card::MAX_TITLE_LENGTH) {
            throw new DomainErrors(['title' => 'board.card.error.title_too_long']);
        }
        if (null !== $command->siteReviewComment) {
            if ($command->siteReviewComment->project !== $command->project) {
                throw new DomainErrors(['siteReviewComment' => 'site_review.attach.error.foreign_card']);
            }
            if (null !== $this->cardSiteReviewComments->findOneBy(['comment' => $command->siteReviewComment])) {
                throw new DomainErrors(['siteReviewComment' => 'site_review.attach.error.already_linked']);
            }
        }

        foreach ($command->pullRequestUrls as $url) {
            if (mb_strlen(trim($url)) > CardPullRequest::MAX_URL_LENGTH) {
                throw new DomainErrors(['pullRequestUrls' => 'board.card.error.pull_request_url_too_long']);
            }
        }

        // Outside the transaction: a refusal inside one rolls it back and
        // closes the EntityManager.
        $documents = $this->documentLinks->resolve($command->project, array_values($command->documentIds));
        $relatedCards = $this->cardLinks->resolve($command->project, null, array_values($command->relatedCards));

        // MAX(position) + 1 and MAX(number) + 1 are both read-then-write: two
        // calls into the same project would otherwise allocate the same rank,
        // and the same card number. Same PESSIMISTIC_WRITE-on-the-project idiom
        // App\Module\SiteReview\Command\AddCommentHandler uses.
        $card = $this->em->wrapInTransaction(function () use ($command, $title, $documents, $relatedCards): Card|string {
            $this->em->lock($command->project, LockMode::PESSIMISTIC_WRITE);

            // Read under the lock: a column deleted or given another terminal
            // flag since the request loaded it decides whether the card may go
            // there and whether it starts finished.
            $columns = $this->boardColumns->findForProjectFresh($command->project);
            if (null !== $command->column && $command->column->project !== $command->project) {
                throw new \LogicException('A card is created only in a column of its own board.');
            }
            $column = $command->column
                ?? array_find($columns, static fn (BoardColumn $candidate): bool => $candidate->isDefault)
                ?? throw new \LogicException('Every board has a default column.');
            if (!\in_array($column, $columns, true)) {
                return UpdateCardHandler::COLUMN_GONE;
            }
            if ($this->cardLinkSync->anyCardGone($relatedCards)) {
                return UpdateCardHandler::LINKED_CARD_GONE;
            }

            $card = new Card(
                project: $command->project,
                column: $column,
                title: $title,
                body: $command->body,
                number: $this->cards->nextNumber($command->project),
                type: $command->type,
                origin: $command->reporter,
                position: $column->terminal
                    ? 0
                    : $this->cards->nextPosition($column),
                // Read once, here: the card then carries its own language, so
                // changing the project's leaves the cards already written alone.
                searchLanguage: $command->project->searchLanguage,
            );

            // A terminal column is entered here as much as by a move, so a card
            // created straight into one still carries the completion it sorts on.
            if ($column->terminal) {
                $card->completedAt = new \DateTimeImmutable();
            }

            $card->replacePullRequests(...$this->pullRequests->linksFor($card, array_values($command->pullRequestUrls)));
            $card->syncDocuments(...$documents);

            $this->em->persist($card);
            $this->cardLinkSync->sync($card, $relatedCards);
            if (null !== $command->siteReviewComment) {
                $this->em->persist(new CardSiteReviewComment($card, $command->siteReviewComment));
            }
            $this->em->flush();

            // After the flush, so the row the UPDATE reads its title and body
            // from exists. Inside the transaction, so the card and its vector
            // commit together.
            $this->searchIndexer->index($card);

            return $card;
        });

        // A refusal leaves the closure as a value, for the reason in AddBoardColumnHandler.
        if (\is_string($card)) {
            throw new DomainErrors([UpdateCardHandler::LINKED_CARD_GONE === $card ? 'relatedCards' : 'column' => $card]);
        }

        // After the commit, never inside it: the sink drains at kernel.terminate,
        // so a record written in the closure outlives a rollback. The title stays
        // out, because it is a sentence a person wrote.
        $this->auditor->record(
            'board.card_created',
            AuditOutcome::Success,
            [
                'cardId' => (string) $card->id,
                'cardNumber' => $card->number,
                'projectId' => (string) $command->project->id,
                'type' => $card->type->value,
                'status' => $card->column->slug,
                'columnId' => (string) $card->column->id,
                'reporter' => $card->reporter->value,
                'pullRequestCount' => \count($card->pullRequests),
                'documentCount' => \count($card->documents),
                'relatedCardCount' => \count($relatedCards),
            ],
            new AuditSubject('card', (string) $card->id),
        );

        if (null !== $command->siteReviewComment) {
            $this->auditor->record(
                'board.site_review_attached',
                AuditOutcome::Success,
                [
                    'cardId' => (string) $card->id,
                    'commentId' => (string) $command->siteReviewComment->id,
                    'projectId' => (string) $command->project->id,
                ],
                new AuditSubject('card', (string) $card->id),
            );
        }

        $this->events->dispatch(new CardChanged(
            $command->project->id ?? throw new \LogicException('Project has no id.'),
            $card->id ?? throw new \LogicException('Card has no id.'),
            CardChanged::CREATED,
            true,
        ));

        return $card;
    }
}
