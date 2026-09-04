<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Document;

final readonly class ReviseDocumentCommand
{
    /**
     * @param list<Document>|null $references the document's complete reference
     *                                        set after this revision; null keeps
     *                                        the current one, an empty list clears it
     * @param ?string             $seriesName raw name as typed; null keeps the
     *                                        document where it is, and a blank
     *                                        string takes it out of its series
     */
    public function __construct(
        public Document $document,
        public string $markdown,
        public string $description,
        public ?string $title = null,
        public ?array $references = null,
        public ?string $seriesName = null,
        public ?int $seriesOrdinal = null,
    ) {
    }
}
