<?php

declare(strict_types=1);

namespace App\Module\Bridge;

/** The outbox event types this module produces. */
final class BridgeEventType
{
    public const string COMMAND = 'bridge.command';

    public const string WORK_REQUEST = 'bridge.work_request';

    private function __construct()
    {
    }
}
