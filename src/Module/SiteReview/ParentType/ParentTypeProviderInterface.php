<?php

declare(strict_types=1);

namespace App\Module\SiteReview\ParentType;

use App\Module\Project\Entity\Project;

/**
 * Names the card types of a project that can have children.
 *
 * SiteReview may not import Board, so Board implements this. The widget uses
 * the answer for its mode "a card for each note, under an epic".
 */
interface ParentTypeProviderInterface
{
    /** @return list<ParentType> in template order, empty when no type can have children */
    public function forProject(Project $project): array;
}
