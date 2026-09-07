<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardMove;
use App\Module\Board\Service\CardMover;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/** Moves a card inside the board. CardMover carries the ranking rules. */
final readonly class MoveCardHandler
{
    public function __construct(
        private CardRepository $cards,
        private CardMover $mover,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(MoveCardCommand $command): Card
    {
        $card = $command->card;

        // Reading a group and renumbering it is read-then-write, so two moves in
        // one project would otherwise interleave into duplicate ranks. Same
        // PESSIMISTIC_WRITE-on-the-project idiom
        // App\Module\SiteReview\Command\AddCommentHandler uses.
        $move = $this->em->wrapInTransaction(function () use ($command, $card): CardMove {
            $this->em->lock($card->project, LockMode::PESSIMISTIC_WRITE);
            // lock() takes the project row and leaves the loaded card as this
            // request read it, which may be before the caller ahead of us in
            // the queue committed. Without the re-read, the move compacts a
            // group the card has already left.
            $this->cards->refreshGroup($card);

            $move = $this->mover->move($card, $command->status, $command->priority, $command->position);
            $this->em->flush();

            return $move;
        });

        // After the commit, never inside it: the sink drains at kernel.terminate,
        // so a record written in the closure outlives a rollback. Both ends of
        // the move, so the trail answers what a card was moved out of.
        $this->auditor->record(
            'board.card_moved',
            AuditOutcome::Success,
            $move->auditContext($card),
            new AuditSubject('card', (string) $card->id),
        );

        return $card;
    }
}
