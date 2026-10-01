<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

use App\Module\Board\Entity\Card;
use Symfony\Component\Uid\Uuid;

/** With no cursor, the newest page. With one, the rows older than the row it names. */
final readonly class ShowCardHistoryCommand
{
    public const string CURSOR_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        public Card $card,
        public ?\DateTimeImmutable $beforeAt = null,
        public ?Uuid $beforeId = null,
    ) {
    }
}
