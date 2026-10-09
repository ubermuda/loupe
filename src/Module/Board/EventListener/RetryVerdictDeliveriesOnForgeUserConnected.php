<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Account\Repository\UserRepository;
use App\Module\Board\Entity\CardVerdictDelivery;
use App\Module\Board\Entity\CardVerdictDeliveryState;
use App\Module\Board\Repository\CardVerdictDeliveryRepository;
use App\Module\Forge\Event\ForgeUserConnected;
use App\Module\Workflow\Contract\CardEvaluations;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Sets the deliveries that an expired connection refused back to pending, so a
 * reviewer who connects again sends the verdicts that waited. A card in a
 * terminal column keeps its refusal.
 */
#[AsEventListener]
final readonly class RetryVerdictDeliveriesOnForgeUserConnected
{
    public function __construct(
        private UserRepository $users,
        private CardVerdictDeliveryRepository $cardVerdictDeliveries,
        private EntityManagerInterface $em,
        private CardEvaluations $evaluations,
    ) {
    }

    public function __invoke(ForgeUserConnected $event): void
    {
        $user = $this->users->find($event->userId);
        if (null === $user) {
            return;
        }

        $refused = $this->cardVerdictDeliveries->findRefusedForReviewer($user, CardVerdictDelivery::REASON_CONNECTION_EXPIRED);
        if ([] === $refused) {
            return;
        }

        $cardIds = [];
        foreach ($refused as $delivery) {
            $delivery->state = CardVerdictDeliveryState::Pending;
            $delivery->reason = null;
            $delivery->settledAt = null;
            $cardId = $delivery->verdict->card->id ?? throw new \LogicException('A stored card has an id.');
            $cardIds[(string) $cardId] = $cardId;
        }
        $this->em->flush();

        if ($this->evaluations->isOn()) {
            $this->evaluations->forCards(array_values($cardIds));
        }
    }
}
