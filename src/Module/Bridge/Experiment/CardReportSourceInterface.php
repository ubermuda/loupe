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

    /**
     * The outcomes of the project's cards with these ids. A card that is gone,
     * or that belongs to another project, has no key.
     *
     * @param list<Uuid> $cardIds
     *
     * @return array<string, CardOutcome> card id => outcome
     */
    public function outcomesFor(Project $project, array $cardIds): array;

    /**
     * The types of the project's cards with these ids. A card that is gone,
     * or that belongs to another project, has no key.
     *
     * @param list<Uuid> $cardIds
     *
     * @return array<string, string> card id => card type value
     */
    public function typesFor(Project $project, array $cardIds): array;

    /** When the project's card history starts. Null when it holds no row. */
    public function historyStartFor(Project $project): ?\DateTimeImmutable;
}
