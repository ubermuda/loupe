<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\ValueObject\BridgeCommandState;
use Symfony\Component\Uid\Uuid;

final readonly class AcknowledgeBridgeCommandCommand
{
    /**
     * @param BridgeCommandState $state  done or refused, the two states a bridge gives
     * @param string|null        $reason null keeps the reason the person gave
     */
    public function __construct(
        public User $owner,
        public Uuid $bridgeId,
        public Uuid $commandId,
        public BridgeCommandState $state,
        public ?string $reason,
    ) {
    }
}
