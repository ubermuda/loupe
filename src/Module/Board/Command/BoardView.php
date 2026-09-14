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
        public int $terminalWindowDays,
        /**
         * Unaddressed site-review comments per card id. A card with none is
         * absent rather than zero, so the template asks with a default. The
         * key is the id as a string, because a Uuid cannot be an array key.
         *
         * @var array<string, int>
         */
        public array $pendingComments = [],
        /** @var list<DeadBridgeRuleView> */
        public array $deadBridgeRules = [],
        /**
         * The column slugs a live bridge rule watches. A rename or a delete of
         * such a column stops the rule matching.
         *
         * @var list<string>
         */
        public array $watchedColumnSlugs = [],
    ) {
    }
}
