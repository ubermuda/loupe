<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Command\BulkMoveBacklogCardsHandler;
use App\Module\Board\Entity\BoardColumn;
use Symfony\Component\Validator\Constraints as Assert;

class BulkMoveBacklogCardsRequest
{
    /** @param list<string> $ids */
    public function __construct(
        #[Assert\All([new Assert\Uuid()])]
        #[Assert\Count(min: 1, max: BulkMoveBacklogCardsHandler::MAX_CARDS)]
        public array $ids = [],

        #[Assert\NotNull]
        public ?BoardColumn $column = null,
    ) {
    }
}
