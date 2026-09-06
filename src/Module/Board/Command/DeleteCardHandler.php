<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/** Deletes a card and its pull request links, which orphanRemoval takes with it. */
final readonly class DeleteCardHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeleteCardCommand $command): void
    {
        $cardId = (string) $command->card->id;
        $projectId = (string) $command->card->project->id;

        $this->em->remove($command->card);
        $this->em->flush();

        $this->logger->info('board.card_deleted', [
            'cardId' => $cardId,
            'projectId' => $projectId,
        ]);
    }
}
