<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\PullRequestUrlResolver;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class CreateCardHandler
{
    public function __construct(
        private CardRepository $cards,
        private PullRequestUrlResolver $pullRequests,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
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

        // MAX(position) + 1 is read-then-write: two calls into the same group
        // would otherwise allocate the same rank and leave the order unstable.
        // Same PESSIMISTIC_WRITE-on-the-project idiom AddCommentHandler uses.
        $card = $this->em->wrapInTransaction(function () use ($command, $title): Card {
            $this->em->lock($command->project, LockMode::PESSIMISTIC_WRITE);

            $card = new Card(
                project: $command->project,
                title: $title,
                body: $command->body,
                type: $command->type,
                priority: $command->priority,
                status: $command->status,
                origin: $command->origin,
                position: CardStatus::Done === $command->status
                    ? 0
                    : $this->cards->nextPosition($command->project, $command->status, $command->priority),
            );

            // Done is entered here as much as by a move, so a card created
            // straight into Done still carries the completion the column sorts on.
            if (CardStatus::Done === $command->status) {
                $card->completedAt = new \DateTimeImmutable();
            }

            $card->replacePullRequests(...$this->pullRequests->linksFor($card, array_values($command->pullRequestUrls)));

            $this->em->persist($card);
            $this->em->flush();

            return $card;
        });

        $this->logger->info('board.card_created', [
            'cardId' => (string) $card->id,
            'projectId' => (string) $command->project->id,
            'status' => $card->status->value,
            'origin' => $card->origin->value,
            'pullRequestCount' => \count($card->pullRequests),
        ]);

        return $card;
    }
}
