<?php

declare(strict_types=1);

namespace App\Module\Bridge\Service;

use App\Module\Project\Entity\Project;
use Symfony\Component\Uid\Uuid;

/** Bridge may not import Board, so Board answers where a card sits. */
interface CardColumnLookupInterface
{
    /** The slug of the card's column now, or null when the project has no such card. */
    public function columnOf(Project $project, Uuid $cardId): ?string;

    /** The id of the card with this number, or null when the project has no such card. */
    public function cardIdOfNumber(Project $project, int $number): ?Uuid;
}
