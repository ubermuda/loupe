<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Account\Entity\User;
use App\Module\Bridge\Entity\CardHold;
use App\Module\Bridge\Entity\WorkerRun;
use App\Module\Bridge\Repository\CardHoldRepository;
use App\Module\Project\Entity\Project;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Holds and releases cards that a person stopped. A hold runs in its own
 * transaction, which nests as a savepoint inside a caller's transaction. A
 * release is one statement, so it joins a caller's transaction.
 */
final readonly class CardHolds
{
    public function __construct(
        private CardHoldRepository $cardHolds,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /** A card that already has a hold keeps it, with its run and holder unchanged. */
    public function hold(Project $project, Uuid $cardId, ?WorkerRun $stoppedRun, ?User $heldBy): CardHold
    {
        return $this->em->wrapInTransaction(function () use ($project, $cardId, $stoppedRun, $heldBy): CardHold {
            // Serialises two holds of one card, which would otherwise both miss
            // the read and trip the unique index.
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);

            $existing = $this->cardHolds->findOneOfCard($project, $cardId);
            if (null !== $existing) {
                return $existing;
            }

            $hold = new CardHold($project, $cardId, $stoppedRun, $heldBy, $this->clock->now());
            $this->em->persist($hold);
            $this->em->flush();

            return $hold;
        });
    }

    /**
     * Answers how many holds went.
     *
     * @param list<Uuid> $cardIds
     */
    public function release(Project $project, array $cardIds): int
    {
        if ([] === $cardIds) {
            return 0;
        }

        return $this->cardHolds->deleteOfCards($project, $cardIds);
    }

    public function isHeld(Project $project, Uuid $cardId): bool
    {
        return $this->cardHolds->existsForCard($project, $cardId);
    }
}
