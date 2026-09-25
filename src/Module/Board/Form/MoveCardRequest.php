<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardColumn;
use Symfony\Component\Validator\Constraints as Assert;

class MoveCardRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?BoardColumn $column = null,

        /** The rank inside the target column, counting from 0. Null appends. */
        #[Assert\PositiveOrZero]
        public ?int $position = null,
        /** Empty keeps the parent, `none` clears it, and a card id sets it. */
        public ?string $parent = null,
        /** The card the drop lands above, which wins over the rank. */
        public ?string $beforeCardId = null,
        /** The card the drop lands below, when no card is below it. */
        public ?string $afterCardId = null,
    ) {
    }
}
