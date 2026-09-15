<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxItem;

final readonly class AnswerInboxItemCommand
{
    public function __construct(
        public InboxItem $item,
        /** Comma-separated option indexes, as the answer form posts them. */
        public string $selectedOptions,
        public string $answerText,
    ) {
    }
}
