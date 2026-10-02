<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

/** Thrown when a move takes a card that the workflow manages to a column its template does not allow. */
final class CardManaged extends \DomainException
{
    public const string MESSAGE = 'board.card.error.managed';

    /** The answer an MCP tool gives, which names the tool that makes the card unmanaged. */
    public const string AGENT_MESSAGE = 'status: This card is managed, and its workflow does not allow this move. card_hold makes the card unmanaged, and then any move is allowed.';

    public function __construct(
        public readonly int $cardNumber,
    ) {
        parent::__construct(\sprintf('Card #%d is managed, and its workflow does not allow this move.', $cardNumber));
    }
}
