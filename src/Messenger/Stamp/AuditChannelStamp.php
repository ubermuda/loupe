<?php

declare(strict_types=1);

namespace App\Messenger\Stamp;

use App\Audit\AuditChannel;
use Symfony\Component\Messenger\Stamp\StampInterface;

/** The channel that was current when the message was dispatched. */
final readonly class AuditChannelStamp implements StampInterface
{
    public function __construct(
        public AuditChannel $channel,
    ) {
    }
}
