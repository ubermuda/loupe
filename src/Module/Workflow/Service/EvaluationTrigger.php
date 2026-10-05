<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Board\Service\BoardAvailability;
use App\Module\Workflow\Contract\CardEvaluations;
use App\Module\Workflow\Messenger\EvaluateCard;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/** Queues one evaluation per card. The Doctrine transport commits the message with the caller's transaction, so a caller may dispatch inside one. */
final readonly class EvaluationTrigger implements CardEvaluations
{
    public function __construct(
        private MessageBusInterface $bus,
        private BoardAvailability $board,
    ) {
    }

    /** @param list<string|Uuid> $cardIds */
    #[\Override]
    public function forCards(array $cardIds): void
    {
        $unique = [];
        foreach ($cardIds as $cardId) {
            $unique[($cardId instanceof Uuid ? $cardId : Uuid::fromString($cardId))->toRfc4122()] = true;
        }
        foreach (array_keys($unique) as $cardId) {
            $this->bus->dispatch(new EvaluateCard($cardId));
        }
    }

    #[\Override]
    public function isOn(): bool
    {
        return $this->board->isEnabled();
    }
}
