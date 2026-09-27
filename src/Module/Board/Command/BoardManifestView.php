<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Project\Entity\Project;

/** The digest of every card face the board page draws, and of the board's frame. */
final readonly class BoardManifestView
{
    public function __construct(
        public Project $project,
        /** @var list<array{string, string}> card id and card digest, in board order */
        public array $cards,
        public string $structure,
    ) {
    }
}
