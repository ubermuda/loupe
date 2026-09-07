<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Project\Entity\Project;

/** Everything one board page renders. */
final readonly class BoardView
{
    /** @param list<BoardColumnView> $columns */
    public function __construct(
        public Project $project,
        public array $columns,
        /** Every Done card the project has, which is more than the column shows. */
        public int $doneTotal,
        public int $doneWindowDays,
        /**
         * Unaddressed site-review comments per card id. A card with none is
         * absent rather than zero, so the template asks with a default. The
         * key is the id as a string, because a Uuid cannot be an array key.
         *
         * @var array<string, int>
         */
        public array $pendingComments = [],
    ) {
    }
}
