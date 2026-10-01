<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;
use App\Module\Board\Messenger\MoveAbandonedCard;
use App\Module\Board\Repository\CardAutomationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Uid\Uuid;

/** Queues the delayed Backlog move of a card whose pull requests all closed with none merged. */
final readonly class AbandonedCardMoves
{
    /** Long enough for a person to link a replacement pull request first. */
    public const int DELAY_MILLISECONDS = 600_000;

    public function __construct(
        private CardAutomationRepository $cardAutomations,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
    ) {
    }

    /** Only the newest queued move acts, so it waits for the last close. */
    public function queue(Card $card): void
    {
        $token = Uuid::v7();
        $this->cardAutomations->findOrCreateForUpdate($card)->abandonedMoveToken = $token;
        $this->em->flush();
        // A named transport keeps PlaywrightSyncMiddleware from handling it inline, before the delay.
        $this->bus->dispatch(
            new MoveAbandonedCard($card->id ?? throw new \LogicException('A stored card has an id.'), $token),
            [new DelayStamp(self::DELAY_MILLISECONDS), new TransportNamesStamp(['async'])],
        );
    }
}
