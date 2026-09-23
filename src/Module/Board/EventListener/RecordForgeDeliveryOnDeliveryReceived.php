<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Command\RecordForgeDeliveryCommand;
use App\Module\Board\Command\RecordForgeDeliveryHandler;
use App\Module\Board\Service\BoardAvailability;
use App\Module\Forge\Event\ForgeDeliveryReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Board's half of a forge delivery. The receiver says what a forge said, and
 * this decides it means something to a card.
 *
 * A board switched off holds no cards to name, so a delivery reaching one is
 * work nobody asked for.
 */
#[AsEventListener]
final readonly class RecordForgeDeliveryOnDeliveryReceived
{
    public function __construct(
        private RecordForgeDeliveryHandler $recordDelivery,
        private BoardAvailability $board,
    ) {
    }

    public function __invoke(ForgeDeliveryReceived $event): void
    {
        if (!$this->board->isEnabled()) {
            return;
        }

        ($this->recordDelivery)(new RecordForgeDeliveryCommand($event->projectId, $event->deliveries));
    }
}
