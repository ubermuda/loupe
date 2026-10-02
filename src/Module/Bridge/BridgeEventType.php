<?php

declare(strict_types=1);

namespace App\Module\Bridge;

/** The outbox event types this module produces. */
final class BridgeEventType
{
    public const string COMMAND = 'bridge.command';

    /** A board type, because the bridge reads it as a fact about the card. */
    public const string CARD_HELD = 'board.card_held';

    public const string CARD_RELEASED = 'board.card_released';

    private function __construct()
    {
    }
}
