<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\Document;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\ValueObject\CommentSignals;
use App\Module\Review\ValueObject\DecisionSummary;
use App\Module\Review\ValueObject\DocumentHeading;

final readonly class ShowDocumentView
{
    /**
     * @param list<Comment>                                                                        $comments
     * @param list<array{versionNumber: int, createdAt: \DateTimeImmutable, description: ?string}> $versions
     * @param list<DocumentHeading>                                                                $headings
     */
    public function __construct(
        public Document $document,
        public DocumentVersion $version,
        public bool $readOnly,
        public array $comments,
        public array $versions,
        public array $headings,
        public int $orphanedCount,
        public CommentSignals $signals,
        public DecisionSummary $decisions,
        public string $decisionMarkedHtml,
        /** The version this reader last engaged with, or null when there is no signal. */
        public ?int $lastSeenVersionNumber,
    ) {
    }
}
