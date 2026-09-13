<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\BoardColumn;
use Symfony\Component\Validator\Constraints as Assert;

class AddBoardColumnRequest
{
    public function __construct(
        #[Assert\Length(max: BoardColumn::MAX_LABEL_LENGTH, normalizer: 'trim')]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $label = null,
    ) {
    }
}
