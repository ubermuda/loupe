<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardGroupOrder;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Deletes a card and its pull request links, which orphanRemoval takes with it. */
final readonly class DeleteCardHandler
{
    public function __construct(
        private CardRepository $cards,
        private CardGroupOrder $groupOrder,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(DeleteCardCommand $command): void
    {
        $card = $command->card;

        // Read before the remove: the flush clears the id. The number and the
        // project are readonly, so no re-read can change them.
        $cardId = (string) $card->id;
        $cardNumber = $card->number;
        $projectId = (string) $card->project->id;

        $this->em->wrapInTransaction(function () use ($card): void {
            // The renumbering below reads the group first, so it takes the same
            // project lock a create or a move does.
            $this->em->lock($card->project, LockMode::PESSIMISTIC_WRITE);
            // lock() takes the project row and leaves the loaded card as the
            // request read it, which may be before the caller ahead of us in
            // the queue committed. Without the re-read, the gap is closed in
            // the group the card was in then rather than the one it is in now.
            $this->cards->refreshGroup($card);

            // Before the remove, so the delete and the renumbering it causes
            // reach the database in one flush.
            $this->groupOrder->compact($card->project, $card->status, $card->priority, $card);

            $this->em->remove($card);
            $this->em->flush();
        });

        // After the commit, never inside it: the sink drains at kernel.terminate,
        // so a record written in the closure outlives a rollback. The group is
        // read here rather than above, because the re-read decides it.
        $this->auditor->record(
            'board.card_deleted',
            AuditOutcome::Success,
            [
                'cardId' => $cardId,
                'cardNumber' => $cardNumber,
                'projectId' => $projectId,
                'status' => $card->status->value,
                'priority' => $card->priority->value,
            ],
            new AuditSubject('card', $cardId),
        );
    }
}
