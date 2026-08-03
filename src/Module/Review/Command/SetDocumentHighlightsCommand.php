<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;

/**
 * Replaces the document's whole set of agent highlights.
 *
 * The set is stated in full rather than added to one passage at a time: an agent
 * decides what matters about a document as a whole, and an add-only tool would
 * leave it unable to change its mind without a second call. An empty $quotes
 * clears the set.
 */
final readonly class SetDocumentHighlightsCommand
{
    /**
     * @param list<string> $quotes verbatim passages of the document's plain text
     */
    public function __construct(
        public Document $document,
        public array $quotes,
    ) {
    }
}
