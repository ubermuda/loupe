<?php

declare(strict_types=1);

namespace App\Module\Bridge\Messenger;

/** An interactive run of the project closed, so its bridges report its usage. */
final readonly class CollectSessionUsage
{
    public function __construct(
        public string $projectId,
        public string $runId,
    ) {
    }
}
