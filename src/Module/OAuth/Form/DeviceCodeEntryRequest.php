<?php

declare(strict_types=1);

namespace App\Module\OAuth\Form;

use Symfony\Component\Validator\Constraints as Assert;

class DeviceCodeEntryRequest
{
    public function __construct(
        #[Assert\Length(max: 32)]
        #[Assert\NotBlank]
        public ?string $userCode = null,
    ) {
    }
}
