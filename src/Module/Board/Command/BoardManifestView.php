<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Project\Entity\Project;

/** The digest and column of every card face the board page draws, and the digest of the board's frame. */
final readonly class BoardManifestView
{
    public function __construct(
        public Project $project,
        /** @var list<array{string, string, string}> card id, card digest and column id, in board order */
        public array $cards,
        public string $structure,
    ) {
    }
}
