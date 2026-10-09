<?php

declare(strict_types=1);

namespace App\Module\Board\Workflow;

use App\Module\Workflow\Contract\ChildFacts;

final readonly class ChildrenFacts implements ChildFacts
{
    /** @param bool $childMergedIntoEpicBranch whether a child pull request merged into the epic branch of this card */
    public function __construct(
        public int $childCount,
        public int $openChildCount,
        public bool $childMergedIntoEpicBranch = false,
    ) {
    }
}
