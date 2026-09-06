<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Repository\CardRepository;
use App\Module\Board\Service\CardGroupOrder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

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
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(MoveCardCommand $command): Card
    {
        $card = $command->card;
        $sourceStatus = $card->status;
        $sourcePriority = $card->priority;
        $staysInGroup = $sourceStatus === $command->status && $sourcePriority === $command->priority;

        $card->status = $command->status;
        $card->priority = $command->priority;

        if (CardStatus::Done === $command->status) {
            // Done sorts by completion and maintains no position, so the rank is
            // parked at 0 and the card keeps the moment it was first finished.
            $card->completedAt ??= new \DateTimeImmutable();
            $card->position = 0;
        } else {
            $card->completedAt = null;

            if ($staysInGroup) {
                // No target rank means the end of the group, which place()
                // clamps to. Going through it rather than through nextPosition()
                // is what stops the card's old rank becoming a gap.
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

        $this->logger->info('board.card_moved', [
            'cardId' => (string) $card->id,
            'projectId' => (string) $card->project->id,
            'status' => $card->status->value,
            'priority' => $card->priority->value,
            'position' => $card->position,
        ]);

        return $card;
    }
}
