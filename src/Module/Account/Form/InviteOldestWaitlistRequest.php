<?php

declare(strict_types=1);

namespace App\Module\Account\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class InviteOldestWaitlistRequest
{
    public function __construct(
        #[Assert\Positive]
        public ?int $count = 10,
    ) {
    }
}
