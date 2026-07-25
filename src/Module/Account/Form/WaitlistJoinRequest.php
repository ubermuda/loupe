<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

final class WaitlistJoinRequest
{
    public function __construct(
        #[Email]
        #[NotBlank]
        public ?string $email = null,
    ) {
    }
}
