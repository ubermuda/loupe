<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use Symfony\Component\Uid\Uuid;

/** An interactive Claude Code session that opens a run on the card as the update applies. */
final readonly class OpenInteractiveRun
{
    public function __construct(
        public Uuid $sessionId,
        public string $name,
    ) {
    }
}
