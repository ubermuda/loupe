<?php

declare(strict_types=1);

namespace App\Module\Diagnostics\Check;

/**
 * Shared between the two checks that read messenger_messages, because the two
 * of them disagreeing about which queue is the failed one would silently make
 * both counts wrong.
 */
final readonly class MessengerQueues
{
    /**
     * Queue name of the `failed` transport in config/packages/messenger.yaml.
     * Everything else in messenger_messages is work the app expects a worker to
     * pick up.
     */
    public const string FAILED = 'failed';
}
