<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use Symfony\Component\Validator\Constraints as Assert;

class ApiTokenRequest
{
    public function __construct(
        #[Assert\Length(max: 100)]
        #[Assert\NotBlank]
        public ?string $label = null,
    ) {
    }
}
