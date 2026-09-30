<?php

declare(strict_types=1);

namespace App\Module\Bridge\Controller\Api;

use App\Module\Bridge\Entity\BridgeCommand;
use App\Module\Bridge\ValueObject\BridgeCommandState;

/**
 * The answer of a bridge to one command. The fields carry no constraints and
 * accept any JSON value, because a validation failure answers without the
 * error code that the bridge reads. The methods check them instead.
 */
final class AcknowledgeBridgeCommandRequest
{
    public function __construct(
        public mixed $state = null,
        public mixed $reason = null,
    ) {
    }

    /** Null unless the state is done or refused. */
    public function state(): ?BridgeCommandState
    {
        $state = \is_string($this->state) ? BridgeCommandState::tryFrom($this->state) : null;

        return \in_array($state, [BridgeCommandState::Done, BridgeCommandState::Refused], true) ? $state : null;
    }

    public function hasTextReason(): bool
    {
        return null === $this->reason || \is_string($this->reason);
    }

    /** Trimmed, and null when blank, so a blank reason keeps the one the person gave. */
    public function reason(): ?string
    {
        $reason = \is_string($this->reason) ? trim($this->reason) : '';

        return '' === $reason ? null : $reason;
    }

    public function reasonTooLong(): bool
    {
        return mb_strlen($this->reason() ?? '') > BridgeCommand::MAX_REASON_LENGTH;
    }
}
