<?php

declare(strict_types=1);

namespace App\Module\Board\Exception;

use Symfony\Component\Uid\Uuid;

/**
 * The transaction of CreateCardHandler ended, and a later step failed. With no
 * outer transaction the card is stored, so a retry would create a second card.
 */
final class CardCommittedException extends \RuntimeException
{
    public function __construct(
        public readonly Uuid $cardId,
        \Throwable $previous,
    ) {
        parent::__construct('The card was created, and a step after the commit failed.', previous: $previous);
    }
}
