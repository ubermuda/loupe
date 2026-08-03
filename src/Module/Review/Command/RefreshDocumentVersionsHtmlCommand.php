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
    ) {
    }
}
