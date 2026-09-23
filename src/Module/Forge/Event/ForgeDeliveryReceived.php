<?php

declare(strict_types=1);

namespace App\Module\Forge\Event;

use App\Module\Forge\ForgeDelivery;
use Symfony\Component\Uid\Uuid;

/**
 * A forge delivery verified, and every repository it names is owned by one
 * project. A listener acts inside that project alone, because another project
 * can link the same pull request.
 */
final readonly class ForgeDeliveryReceived
{
    /** @param non-empty-list<ForgeDelivery> $deliveries */
    public function __construct(
        public Uuid $projectId,
        public array $deliveries,
    ) {
    }
}
