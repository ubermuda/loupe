<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Exception\DomainErrors;
use App\Module\Bridge\Service\CardColumnLookupInterface;
use App\Module\Bridge\Service\CardPause;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A person or an agent pauses the agents on a card. The hold and its outbox event commit
 * together, so a bridge never learns of a hold that rolled back.
 */
final readonly class PauseCardAgentsHandler
{
    public const string ALREADY_PAUSED = 'bridge.card_hold.error.already_paused';

    public const string CARD_GONE = 'bridge.card_hold.error.card_gone';

    public function __construct(
        private CardPause $pause,
        private CardColumnLookupInterface $cards,
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(PauseCardAgentsCommand $command): void
    {
        $project = $command->project;
        $cardId = $command->cardId;

        // Returns the refusal, because an exception inside the closure closes the entity manager.
        $refusal = $this->em->wrapInTransaction(function () use ($command, $project, $cardId): ?string {
            // The lock that card moves and resumes take, so the read below stays true until the commit.
            $this->em->lock($project, LockMode::PESSIMISTIC_WRITE);
            // A card delete takes the same lock, so a card that exists here outlives the commit.
            if (null === $this->cards->columnOf($project, $cardId)) {
                return self::CARD_GONE;
            }
            if (!$this->pause->take($project, $cardId, $command->requestedBy, $command->actor)) {
                return self::ALREADY_PAUSED;
            }
            $this->em->flush();

            return null;
        });

        if (null !== $refusal) {
            throw new DomainErrors(['card' => $refusal]);
        }

        $this->pause->announce($project, $cardId);
    }
}
