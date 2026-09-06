<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Repository\CardRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Moves a card inside the board.
 *
 * Ranks are plain integers renumbered per group rather than fractional, so a
 * group's order is readable in the table and an ORDER BY needs no tie-break.
 * A group holds the cards of one (project, status, priority) triple.
 */
final readonly class MoveCardHandler
{
    public function __construct(
        private CardRepository $cards,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(MoveCardCommand $command): Card
    {
        $card = $command->card;
        $staysInGroup = $card->status === $command->status && $card->priority === $command->priority;

        $card->status = $command->status;
        $card->priority = $command->priority;

        if (CardStatus::Done === $command->status) {
            // Done sorts by completion and maintains no position, so the rank is
            // parked at 0 and the card keeps the moment it was first finished.
            $card->completedAt ??= new \DateTimeImmutable();
            $card->position = 0;
        } else {
            $card->completedAt = null;

            if ($staysInGroup && null !== $command->position) {
                $this->renumber($card, $command->position);
            } else {
                $card->position = $this->cards->nextPosition($card->project, $command->status, $command->priority);
            }
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

    /** Puts the card at the wanted rank in its own group, then renumbers the group from 0. */
    private function renumber(Card $card, int $position): void
    {
        $others = array_values(array_filter(
            $this->cards->findGroup($card->project, $card->status, $card->priority),
            static fn (Card $member): bool => $member !== $card,
        ));

        $target = max(0, min($position, \count($others)));
        array_splice($others, $target, 0, [$card]);

        foreach ($others as $index => $member) {
            $member->position = $index;
        }
    }
}
