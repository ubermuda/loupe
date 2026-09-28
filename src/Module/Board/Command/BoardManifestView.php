<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Project\Entity\Project;

/** The digest and column of every card and lane epic on the board page, the digest of the board's frame, and the total of each history link. */
final readonly class BoardManifestView
{
    public function __construct(
        public Project $project,
        /** @var list<array{0: string, 1: string, 2: string, 3?: string}> card id, card digest, column id and, for a lane epic only, its lane head digest; in board order, then the lane epics in Backlog */
        public array $cards,
        public string $structure,
        /** @var array<string, int> the card count of each terminal column, by column id */
        public array $terminalTotals,
    ) {
    }
}
