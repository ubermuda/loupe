<?php

declare(strict_types=1);

namespace App\Module\Account\Form\Admin;

use App\Module\Account\Entity\User;
use Symfony\Component\Validator\Constraints as Assert;

final class SuspendUserRequest
{
    public function __construct(
        #[Assert\Length(max: User::MAX_SUSPENDED_REASON_LENGTH)]
        public ?string $reason = null,
    ) {
    }
}
