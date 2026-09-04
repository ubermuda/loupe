<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\ValueObject\CommentSignals;
use App\Module\Review\ValueObject\DiffRefusal;
use App\Module\Review\ValueObject\DiffView;
use App\Module\Review\ValueObject\DocumentDiff;
use App\Module\Review\ValueObject\RenderedDiff;

final readonly class DiffDocumentVersionsView
{
    /**
     * Three states the page tells apart. A refusal carries `diffRefusal` and no
     * diff. Two versions that read the same carry a `diff` that holds no change.
     * Otherwise `changeCount` is set, and the field the pane reads is the one
     * `view` names: `renderedDiff` for the rendered view, `diff` for the source
     * one, which is why only the showing view is ever built.
     *
     * @param list<Comment>                                                                        $comments
     * @param list<array{versionNumber: int, createdAt: \DateTimeImmutable, description: ?string}> $versions
     */
    public function __construct(
        public DocumentVersion $version,
        public DiffView $view,
        public ?DocumentDiff $diff,
        public ?RenderedDiff $renderedDiff,
        public ?DiffRefusal $diffRefusal,
        public ?int $changeCount,
        public array $comments,
        public array $versions,
        public int $orphanedCount,
        public CommentSignals $signals,
    ) {
    }
}
