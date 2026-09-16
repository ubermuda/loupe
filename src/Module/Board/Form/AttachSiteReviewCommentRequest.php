<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\Card;
use Symfony\Component\Validator\Constraints as Assert;

final class AttachSiteReviewCommentRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?Card $card = null,
    ) {
    }
}
