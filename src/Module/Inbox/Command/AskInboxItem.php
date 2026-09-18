<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Inbox\Entity\InboxItemKind;

/** One item as the agent wrote it. The handler trims, checks and links it. */
final readonly class AskInboxItem
{
    /**
     * @param list<string> $options
     * @param list<string> $cardIds
     * @param list<string> $documentIds
     */
    public function __construct(
        public InboxItemKind $kind,
        public string $title,
        public ?string $body = null,
        public array $options = [],
        public bool $multiple = false,
        public bool $freeText = false,
        /** Null defaults to blocking for questions only. */
        public ?bool $blocking = null,
        public array $cardIds = [],
        public array $documentIds = [],
        public ?string $reviewDocumentId = null,
        public ?string $reviewPullRequestId = null,
    ) {
    }
}
