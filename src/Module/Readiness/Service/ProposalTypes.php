<?php

declare(strict_types=1);

namespace App\Module\Readiness\Service;

use App\Module\Project\Entity\Project;
use App\Module\Workflow\Contract\CardTypeCatalog;

/** A grouping type is not a proposal type: Loupe creates each ticked proposal as a card that has no children. */
final readonly class ProposalTypes
{
    public function __construct(
        private CardTypeCatalog $cardTypes,
    ) {
    }

    /** @return list<string> the type keys of the project that a proposal may use */
    public function acceptedFor(Project $project): array
    {
        $types = $this->cardTypes->forProject($project->requireId());

        return array_values(array_diff($types->keys(), $types->withChildren()));
    }
}
