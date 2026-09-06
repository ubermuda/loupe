<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardPriority;
use App\Module\Board\Entity\CardStatus;
use App\Module\Board\Repository\CardRepository;
use App\Module\Project\Entity\Project;
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
                // No target rank means the end of the group, which the renumber
                // clamps to. Going through it rather than through nextPosition()
                // is what stops the card's old rank becoming a gap.
                $this->renumber($card, $command->position ?? \PHP_INT_MAX);
            } else {
                $card->position = $this->cards->nextPosition($card->project, $command->status, $command->priority);
            }
        }

        // The group the card left keeps its own numbering. Done is skipped
        // because it maintains no position to compact.
        if (!$staysInGroup && CardStatus::Done !== $sourceStatus) {
            $this->compact($card, $card->project, $sourceStatus, $sourcePriority);
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
        $others = $this->groupWithout($card, $card->project, $card->status, $card->priority);

        $target = max(0, min($position, \count($others)));
        array_splice($others, $target, 0, [$card]);

        foreach ($others as $index => $member) {
            $member->position = $index;
        }
    }

    /** Closes the gap the card left behind in the group it came from. */
    private function compact(Card $card, Project $project, CardStatus $status, CardPriority $priority): void
    {
        foreach ($this->groupWithout($card, $project, $status, $priority) as $index => $member) {
            $member->position = $index;
        }
    }

    /**
     * The group read in board order, without the card being moved.
     *
     * The read happens before the flush, so the card is still in its source
     * group in the database and has to be dropped by identity.
     *
     * @return list<Card>
     */
    private function groupWithout(Card $card, Project $project, CardStatus $status, CardPriority $priority): array
    {
        return array_values(array_filter(
            $this->cards->findGroup($project, $status, $priority),
            static fn (Card $member): bool => $member !== $card,
        ));
    }
}
