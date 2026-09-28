<?php

declare(strict_types=1);

namespace App\Module\Bridge\View;

use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/** Bridge may not import Board, so Board answers this for the run list. */
interface CardTitleSourceInterface
{
    /**
     * The titles of the project's cards with these ids. A card that is gone,
     * or that belongs to another project, has no key.
     *
     * @param list<Uuid> $cardIds
     *
     * @return array<string, string> card id => title
     */
    public function titlesFor(Project $project, array $cardIds): array;
}
