<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\Comment;
use App\Module\Review\Entity\DocumentVersion;
use App\Module\Review\ValueObject\CommentSignals;
use App\Module\Review\ValueObject\DiffRefusal;
use App\Module\Review\ValueObject\DiffView;
use App\Module\Review\ValueObject\DocumentDiff;
use App\Module\Review\ValueObject\DocumentHeading;
use App\Module\Review\ValueObject\RenderedDiff;
use App\Module\Review\ValueObject\SideBySideDiff;

final readonly class DiffDocumentVersionsView
{
    /**
     * Three states the page tells apart. A refusal carries `diffRefusal` and no
     * diff. Two versions that read the same carry a `diff` that holds no change.
     * Otherwise `changeCount` is set, and the field the pane reads is the one
     * `view` names: `renderedDiff` for the rendered view, `diff` for the source
     * one, `sideBySide` for the two columns, which is why only the showing view
     * is ever built.
     *
     * `commentingEnabled` says whether a reviewer may comment on this pane. It
     * needs the rendered view, and it needs the newer side to be the version a
     * comment would land on, which is the latest one. The side-by-side view
     * therefore never comments, since it leaves `renderedDiff` null.
     *
     * `headings` lists the headings of the pane that is showing, in document
     * order, and is empty for the source view, which renders none to link to.
     *
     * @param list<Comment>                                                                        $comments
     * @param list<DocumentHeading>                                                                $headings
     * @param list<array{versionNumber: int, createdAt: \DateTimeImmutable, description: ?string}> $versions
     */
    public function __construct(
        public DocumentVersion $version,
        public DiffView $view,
        public ?DocumentDiff $diff,
        public ?RenderedDiff $renderedDiff,
        public ?SideBySideDiff $sideBySide,
        public ?DiffRefusal $diffRefusal,
        public ?int $changeCount,
        public array $headings,
        public bool $commentingEnabled,
        public array $comments,
        public array $versions,
        public CommentSignals $signals,
    ) {
    }
}
