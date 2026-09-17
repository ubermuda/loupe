<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class SetDefaultBoardColumnRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public ?string $expectedDefaultId = null,
    ) {
    }
}
