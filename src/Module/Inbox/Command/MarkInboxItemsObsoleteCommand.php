<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

/** Cards have moved. The columns they now sit in say whether their items still wait on anything. */
final readonly class MarkInboxItemsObsoleteCommand
{
    /** @param list<string> $cardIds */
    public function __construct(
        public array $cardIds,
    ) {
    }
}
