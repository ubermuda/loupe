<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Forge\ForgeDelivery;
use Symfony\Component\Uid\Uuid;

final readonly class RecordForgeDeliveryCommand
{
    /** @param list<ForgeDelivery> $deliveries */
    public function __construct(
        public Uuid $projectId,
        public array $deliveries,
    ) {
    }
}
