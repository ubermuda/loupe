<?php

declare(strict_types=1);

namespace App\Module\Bridge\Cost;

use App\Module\Project\Entity\Project;

/** Bridge may not import Board, so Board answers this for the cost chart. */
interface FinishedCardSourceInterface
{
    /**
     * The finished cards of the project, oldest completion first. A null start
     * returns every finished card.
     *
     * @return list<FinishedCard>
     */
    public function finishedCards(Project $project, ?\DateTimeImmutable $completedSince): array;
}
