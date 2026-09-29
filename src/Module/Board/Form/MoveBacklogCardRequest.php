<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardColumn;
use Symfony\Component\Validator\Constraints as Assert;

class MoveBacklogCardRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?BoardColumn $column = null,
    ) {
    }
}
