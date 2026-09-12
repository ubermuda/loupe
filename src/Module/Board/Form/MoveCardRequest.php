<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardColumn;
use App\Module\Board\Entity\CardPriority;
use Symfony\Component\Validator\Constraints as Assert;

class MoveCardRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?BoardColumn $column = null,

        #[Assert\NotNull]
        public ?CardPriority $priority = null,

        /** The rank inside the target group, counting from 0. Null appends. */
        #[Assert\PositiveOrZero]
        public ?int $position = null,
    ) {
    }
}
