<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

final readonly class RefreshDocumentVersionsHtmlCommand
{
    public function __construct(
        /**
         * Proceed even where re-rendering strands existing comment anchors.
         * Defaults to refusing, because the damage is silent: the browser
         * re-locates each anchor by quote, so a comment whose quote no longer
         * appears simply stops highlighting and stays in the sidebar looking
         * healthy until some later revision marks it orphaned.
         */
        public bool $acceptCommentOrphaning = false,
        /**
         * Move every anchor onto the re-rendered text instead of leaving it
         * describing the old one. Comments whose quote the new text no longer
         * contains are marked orphaned here rather than waiting for someone to
         * revise the document, which is what made the damage silent.
         */
        public bool $reanchor = false,
    ) {
    }
}
