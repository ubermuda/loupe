<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Exception\DomainErrors;
use App\Module\Board\Entity\Card;
use App\Module\Board\Service\PullRequestUrlResolver;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

final readonly class UpdateCardHandler
{
    public function __construct(
        private MoveCardHandler $moveCard,
        private PullRequestUrlResolver $pullRequests,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(UpdateCardCommand $command): Card
    {
        $card = $command->card;

        // A field the command carries may hold what the card already holds, so
        // the record reports what changed rather than what was submitted.
        $originalTitle = $card->title;
        $originalBody = $card->body;
        $originalType = $card->type;

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
        $moved = $status !== $card->status || $priority !== $card->priority;
        if ($moved) {
            ($this->moveCard)(new MoveCardCommand($card, $status, $priority));
        } else {
            $this->em->flush();
        }

        // After the write, and after the move that carries it. `moved` names the
        // paired board.card_moved record, which holds the status and priority
        // this one does not.
        $this->auditor->record(
            'board.card_updated',
            AuditOutcome::Success,
            [
                'cardId' => (string) $card->id,
                'cardNumber' => $card->number,
                'projectId' => (string) $card->project->id,
                'titleChanged' => $originalTitle !== $card->title,
                'bodyChanged' => $originalBody !== $card->body,
                'typeChanged' => $originalType !== $card->type,
                'pullRequestsReplaced' => null !== $command->pullRequestUrls,
                'moved' => $moved,
            ],
            new AuditSubject('card', (string) $card->id),
        );

        return $card;
    }
}
