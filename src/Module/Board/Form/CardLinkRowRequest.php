<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use App\Module\Board\Entity\Card;
use App\Module\Board\Entity\CardLinkKind;
use Symfony\Component\Validator\Constraints as Assert;

/** One linked card row of the card form: the other card, and how this card reads it. */
class CardLinkRowRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?Card $card = null,

        #[Assert\NotNull]
        public ?CardLinkKind $kind = CardLinkKind::RelatesTo,
    ) {
    }
}
