<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

final readonly class SaveDecisionAnswerResult
{
    public function __construct(
        /** False when the stored answer already matched the request. */
        public bool $changed,
        /** True after a Clear, or when the decision is left with no pick and no note. */
        public bool $cleared,
    ) {
    }
}
