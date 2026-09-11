<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPullRequest;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardSearchIndexer;
use App\Module\Board\Service\DocumentLinkResolver;
use App\Module\Board\Service\PullRequestUrlResolver;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class CreateCardHandler
{
    public function __construct(
        private CardRepository $cards,
        private PullRequestUrlResolver $pullRequests,
        private DocumentLinkResolver $documentLinks,
        private CardSearchIndexer $searchIndexer,
        private EntityManagerInterface $em,
        private Auditor $auditor,
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

        foreach ($command->pullRequestUrls as $url) {
            if (mb_strlen(trim($url)) > CardPullRequest::MAX_URL_LENGTH) {
                throw new DomainErrors(['pullRequestUrls' => 'board.card.error.pull_request_url_too_long']);
            }
        }

        // Outside the transaction: a refusal inside one rolls it back and
        // closes the EntityManager.
        $documents = $this->documentLinks->resolve($command->project, array_values($command->documentIds));

        // MAX(position) + 1 and MAX(number) + 1 are both read-then-write: two
        // calls into the same project would otherwise allocate the same rank,
        // and the same card number. Same PESSIMISTIC_WRITE-on-the-project idiom
        // App\Module\SiteReview\Command\AddCommentHandler uses.
        $card = $this->em->wrapInTransaction(function () use ($command, $title, $documents): Card {
            $this->em->lock($command->project, LockMode::PESSIMISTIC_WRITE);

            $card = new Card(
                project: $command->project,
                title: $title,
                body: $command->body,
                number: $this->cards->nextNumber($command->project),
                type: $command->type,
                priority: $command->priority,
                status: $command->status,
                origin: $command->origin,
                position: CardStatus::Done === $command->status
                    ? 0
                    : $this->cards->nextPosition($command->project, $command->status, $command->priority),
                // Read once, here: the card then carries its own language, so
                // changing the project's leaves the cards already written alone.
                searchLanguage: $command->project->searchLanguage,
            );

            // Done is entered here as much as by a move, so a card created
            // straight into Done still carries the completion the column sorts on.
            if (CardStatus::Done === $command->status) {
                $card->completedAt = new \DateTimeImmutable();
            }

            $card->replacePullRequests(...$this->pullRequests->linksFor($card, array_values($command->pullRequestUrls)));
            $card->syncDocuments(...$documents);

            $this->em->persist($card);
            $this->em->flush();

            // After the flush, so the row the UPDATE reads its title and body
            // from exists. Inside the transaction, so the card and its vector
            // commit together.
            $this->searchIndexer->index($card);

            return $card;
        });

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
                'priority' => $card->priority->value,
                'status' => $card->status->value,
                'origin' => $card->origin->value,
                'pullRequestCount' => \count($card->pullRequests),
                'documentCount' => \count($card->documents),
            ],
            new AuditSubject('card', (string) $card->id),
        );

        return $card;
    }
}
