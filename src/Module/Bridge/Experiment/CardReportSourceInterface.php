<?php

declare(strict_types=1);

namespace App\Module\Bridge\Experiment;

use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/** Bridge may not import Board, so Board answers this for the experiment report. */
interface CardReportSourceInterface
{
    /**
     * The columns of the project's cards with these ids. A card that is gone,
     * or that belongs to another project, has no key.
     *
     * @param list<Uuid> $cardIds
     *
     * @return array<string, CardColumn> card id => column
     */
    public function columnsFor(Project $project, array $cardIds): array;
}
