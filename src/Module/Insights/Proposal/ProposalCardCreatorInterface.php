<?php

declare(strict_types=1);

namespace App\Module\Insights\Proposal;

use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/** Insights may not import Board, so Board creates the card of an accepted proposal. */
interface ProposalCardCreatorInterface
{
    /**
     * Creates the card in the backlog of the project and answers its id. It joins
     * the transaction of the caller, so a rollback there removes the card.
     *
     * @throws \App\Exception\DomainErrors when the board refuses the card
     */
    public function createBacklogCard(Project $project, ProposalCard $card): Uuid;
}
