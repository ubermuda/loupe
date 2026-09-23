<?php

declare(strict_types=1);

namespace App\Module\Forge\Event;

use App\Module\Forge\ForgeDelivery;

/**
 * A forge delivery arrived and its signature verified.
 *
 * The receiver knows nothing about what a delivery means. It says what a forge
 * said, and whatever cares listens. That is what keeps this package free of
 * every Loupe type.
 */
final readonly class ForgeDeliveryReceived
{
    /** @param non-empty-list<ForgeDelivery> $deliveries */
    public function __construct(
        public array $deliveries,
    ) {
    }
}
