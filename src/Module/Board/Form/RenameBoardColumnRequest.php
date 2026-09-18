<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

class RenameBoardColumnRequest extends AddBoardColumnRequest
{
    public function __construct(
        ?string $label = null,
        public ?string $expectedLabel = null,
    ) {
        parent::__construct($label);
    }
}
