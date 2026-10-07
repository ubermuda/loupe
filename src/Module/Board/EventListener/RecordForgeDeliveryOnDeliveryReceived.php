<?php

declare(strict_types=1);

namespace App\Module\Board\EventListener;

use App\Module\Board\Command\RecordForgeDeliveryCommand;
use App\Module\Board\Command\RecordForgeDeliveryHandler;
use App\Module\Forge\Event\ForgeDeliveryReceived;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Board's half of a forge delivery. The receiver says what a forge said, and
 * this decides it means something to a card.
 */
#[AsEventListener]
final readonly class RecordForgeDeliveryOnDeliveryReceived
{
    public function __construct(
        private RecordForgeDeliveryHandler $recordDelivery,
    ) {
    }

    public function __invoke(ForgeDeliveryReceived $event): void
    {
        ($this->recordDelivery)(new RecordForgeDeliveryCommand($event->projectId, $event->deliveries, $event->stateReadable));
    }
}
