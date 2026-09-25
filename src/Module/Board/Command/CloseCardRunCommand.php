<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use Symfony\Component\Uid\Uuid;

final readonly class CloseCardRunCommand
{
    public function __construct(
        public Card $card,
        public Uuid $sessionId,
    ) {
    }
}
