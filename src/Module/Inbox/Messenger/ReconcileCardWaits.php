<?php

declare(strict_types=1);

namespace App\Module\Inbox\Messenger;

final readonly class ReconcileCardWaits
{
    /** @param list<string>|null $cardIds null for every card of the project that may wait */
    public function __construct(
        public string $projectId,
        public ?array $cardIds,
    ) {
    }
}
