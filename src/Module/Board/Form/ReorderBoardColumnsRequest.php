<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use Symfony\Component\Validator\Constraints as Assert;

class ReorderBoardColumnsRequest
{
    public function __construct(
        /** Every column id of the board, comma-separated, in the new order. */
        #[Assert\NotBlank]
        public ?string $order = null,
        public ?string $expectedOrder = null,
    ) {
    }
}
