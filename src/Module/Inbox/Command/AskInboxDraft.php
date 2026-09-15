<?php

declare(strict_types=1);

namespace App\Module\Inbox\Command;

use App\Module\Board\Entity\Card;
use App\Module\Inbox\Entity\InboxItemKind;
use App\Module\Review\Entity\Document;

/** One item after the handler checked it, with its links resolved. */
final readonly class AskInboxDraft
{
    /**
     * @param list<string>   $options
     * @param list<Card>     $cards
     * @param list<Document> $documents
     */
    public function __construct(
        public InboxItemKind $kind,
        public string $title,
        public ?string $body,
        public array $options,
        public bool $multiple,
        public bool $freeText,
        public bool $blocking,
        public array $cards,
        public array $documents,
    ) {
    }
}
