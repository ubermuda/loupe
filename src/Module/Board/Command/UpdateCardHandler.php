<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Service\PullRequestUrlResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class UpdateCardHandler
{
    public function __construct(
        private MoveCardHandler $moveCard,
        private PullRequestUrlResolver $pullRequests,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(UpdateCardCommand $command): Card
    {
        $card = $command->card;

        if (null !== $command->title) {
            $title = trim($command->title);
            if ('' === $title) {
                throw new DomainErrors(['title' => 'board.card.error.title_blank']);
            }
            if (mb_strlen($title) > Card::MAX_TITLE_LENGTH) {
                throw new DomainErrors(['title' => 'board.card.error.title_too_long']);
            }
            $card->title = $title;
        }

        if (null !== $command->body) {
            $card->body = $command->body;
        }

        if (null !== $command->type) {
            $card->type = $command->type;
        }

        if (null !== $command->pullRequestUrls) {
            $card->replacePullRequests(...$this->pullRequests->linksFor($card, array_values($command->pullRequestUrls)));
        }

        $card->updatedAt = new \DateTimeImmutable();

        // One write for the whole update. A status or priority change is a move,
        // which renumbers a group and decides the completion timestamp, and the
        // move's own flush carries the field changes above with it. Flushing
        // them first would commit half an update whose move then failed.
        $status = $command->status ?? $card->status;
        $priority = $command->priority ?? $card->priority;
        if ($status !== $card->status || $priority !== $card->priority) {
            ($this->moveCard)(new MoveCardCommand($card, $status, $priority));
        } else {
            $this->em->flush();
        }

        $this->logger->info('board.card_updated', [
            'cardId' => (string) $card->id,
            'projectId' => (string) $card->project->id,
            'status' => $card->status->value,
            'pullRequestsReplaced' => null !== $command->pullRequestUrls,
        ]);

        return $card;
    }
}
