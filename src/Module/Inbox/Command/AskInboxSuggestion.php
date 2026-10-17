<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

/** One action that the asking agent suggests on an item, as it wrote it. */
final readonly class AskInboxSuggestion
{
    public function __construct(
        public string $action,
        public string $reason,
        public bool $recommended = false,
        public ?string $cardId = null,
        public ?AskInboxRunCommand $command = null,
        public ?string $proposedEntry = null,
        public ?string $prompt = null,
    ) {
    }
}
