<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardGroupOrder;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ubermuda\AuditBundle\Auditor;
use Ubermuda\AuditBundle\AuditOutcome;
use Ubermuda\AuditBundle\AuditSubject;

/**
 * Moves a card inside the board.
 *
 * Ranks are plain integers renumbered per group rather than fractional, so a
 * group's order is readable in the table and an ORDER BY needs no tie-break.
 * A group holds the cards of one (project, status, priority) triple, and every
 * group this handler touches comes out numbered from 0 with no gaps.
 */
final readonly class MoveCardHandler
{
    public function __construct(
        private CardRepository $cards,
        private CardGroupOrder $groupOrder,
        private EntityManagerInterface $em,
        private Auditor $auditor,
    ) {
    }

    public function __invoke(MoveCardCommand $command): Card
    {
        $card = $command->card;

        // Read here rather than in the closure, so the record can still name the
        // group the card left. lock() takes the row and leaves the loaded entity
        // as it was, so these are the values the closure reads too.
        $sourceStatus = $card->status;
        $sourcePriority = $card->priority;

        // Reading a group and renumbering it is read-then-write, so two moves in
        // one project would otherwise interleave into duplicate ranks. Same
        // PESSIMISTIC_WRITE-on-the-project idiom
        // App\Module\SiteReview\Command\AddCommentHandler uses.
        $this->em->wrapInTransaction(function () use ($command, $card, $sourceStatus, $sourcePriority): void {
            $this->em->lock($card->project, LockMode::PESSIMISTIC_WRITE);

            $staysInGroup = $sourceStatus === $command->status && $sourcePriority === $command->priority;

            $card->status = $command->status;
            $card->priority = $command->priority;

            if (CardStatus::Done === $command->status) {
                // Done sorts by completion and maintains no position, so the rank
                // is parked at 0 and the card keeps the moment it was first
                // finished.
                $card->completedAt ??= new \DateTimeImmutable();
                $card->position = 0;
            } else {
                $card->completedAt = null;

                if ($staysInGroup) {
                    // No target rank means the end of the group, which place()
                    // clamps to. Going through it rather than through
                    // nextPosition() is what stops the old rank becoming a gap.
                    $this->groupOrder->place($card, $command->position ?? \PHP_INT_MAX);
                } else {
                    $card->position = $this->cards->nextPosition($card->project, $command->status, $command->priority);
                }
            }

            if (!$staysInGroup) {
                $this->groupOrder->compact($card->project, $sourceStatus, $sourcePriority, $card);
            }

            $card->updatedAt = new \DateTimeImmutable();
            $this->em->flush();
        });

        // After the commit, never inside it: the sink drains at kernel.terminate,
        // so a record written in the closure outlives a rollback. Both ends of
        // the move, so the trail answers what a card was moved out of.
        $this->auditor->record(
            'board.card_moved',
            AuditOutcome::Success,
            [
                'cardId' => (string) $card->id,
                'cardNumber' => $card->number,
                'projectId' => (string) $card->project->id,
                'fromStatus' => $sourceStatus->value,
                'fromPriority' => $sourcePriority->value,
                'toStatus' => $card->status->value,
                'toPriority' => $card->priority->value,
                'position' => $card->position,
            ],
            new AuditSubject('card', (string) $card->id),
        );

        return $card;
    }
}
