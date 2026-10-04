<?php

declare(strict_types=1);

namespace App\Module\Bridge\Command;

use App\Module\Account\Entity\User;
use App\Module\Bridge\ValueObject\WorkRequestState;
use Symfony\Component\Uid\Uuid;

final readonly class SettleWorkRequestCommand
{
    /**
     * @param WorkRequestState $state  done or refused, the two results a bridge gives
     * @param string|null      $reason a code that matches WorkRequest::REASON_PATTERN
     */
    public function __construct(
        public User $owner,
        public Uuid $bridgeId,
        public Uuid $workRequestId,
        public Uuid $claimToken,
        public WorkRequestState $state,
        public ?string $reason,
    ) {
    }
}
