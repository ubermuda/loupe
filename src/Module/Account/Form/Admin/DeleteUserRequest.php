<?php

declare(strict_types=1);

namespace App\Module\Account\Form\Admin;

use Symfony\Component\Validator\Constraints\NotBlank;

final class DeleteUserRequest
{
    public function __construct(
        #[NotBlank]
        public ?string $confirmEmail = null,
    ) {
    }
}
