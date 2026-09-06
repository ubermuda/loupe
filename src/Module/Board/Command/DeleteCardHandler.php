<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Service\CardGroupOrder;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/** Deletes a card and its pull request links, which orphanRemoval takes with it. */
final readonly class DeleteCardHandler
{
    public function __construct(
        private CardGroupOrder $groupOrder,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeleteCardCommand $command): void
    {
        $card = $command->card;
        $cardId = (string) $card->id;
        $projectId = (string) $card->project->id;

        $this->em->wrapInTransaction(function () use ($card): void {
            // The renumbering below reads the group first, so it takes the same
            // project lock a create or a move does.
            $this->em->lock($card->project, LockMode::PESSIMISTIC_WRITE);

            // Before the remove, so the delete and the renumbering it causes
            // reach the database in one flush.
            $this->groupOrder->compact($card->project, $card->status, $card->priority, $card);

            $this->em->remove($card);
            $this->em->flush();
        });

        $this->logger->info('board.card_deleted', [
            'cardId' => $cardId,
            'projectId' => $projectId,
        ]);
    }
}
