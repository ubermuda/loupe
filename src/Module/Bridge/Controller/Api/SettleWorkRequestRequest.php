<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\WorkRequest;
use App\Module\Bridge\ValueObject\WorkRequestState;
use Symfony\Component\Uid\Uuid;

/**
 * The result of a bridge for one claimed work request. The fields carry no
 * constraints and accept any JSON value, because a validation failure answers
 * without the error code that the bridge reads. The methods check them instead.
 */
final class SettleWorkRequestRequest
{
    public function __construct(
        public mixed $claimToken = null,
        public mixed $state = null,
        public mixed $reason = null,
    ) {
    }

    /** Null unless the state is done or refused. */
    public function state(): ?WorkRequestState
    {
        $state = \is_string($this->state) ? WorkRequestState::tryFrom($this->state) : null;

        return \in_array($state, [WorkRequestState::Done, WorkRequestState::Refused], true) ? $state : null;
    }

    public function claimToken(): ?Uuid
    {
        return \is_string($this->claimToken) && Uuid::isValid($this->claimToken) ? Uuid::fromString($this->claimToken) : null;
    }

    public function hasValidReason(): bool
    {
        $reason = $this->reason();

        return (null === $this->reason || \is_string($this->reason))
            && (null === $reason || 1 === preg_match(WorkRequest::REASON_PATTERN, $reason));
    }

    /** Trimmed, and null when blank. */
    public function reason(): ?string
    {
        $reason = \is_string($this->reason) ? trim($this->reason) : '';

        return '' === $reason ? null : $reason;
    }
}
