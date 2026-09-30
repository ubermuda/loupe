<?php

declare(strict_types=1);

namespace App\Module\Billing\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateBetaInviteRequest
{
    public function __construct(
        #[Assert\Length(max: 255)]
        public ?string $note = null,
    ) {
    }
}
