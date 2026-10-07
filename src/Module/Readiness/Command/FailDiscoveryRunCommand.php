<?php

declare(strict_types=1);

namespace App\Module\Readiness\Command;

use Symfony\Component\Uid\Uuid;

final readonly class FailDiscoveryRunCommand
{
    public function __construct(
        public Uuid $cardId,
        /** A short code such as no-taker, or the failure reason a bridge reported. */
        public string $reason,
    ) {
    }
}
