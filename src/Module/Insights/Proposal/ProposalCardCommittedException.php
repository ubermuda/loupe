<?php

declare(strict_types=1);

namespace App\Module\Insights\Proposal;

use Symfony\Component\Uid\Uuid;

/** The board stored the card, and a step after the commit failed. */
final class ProposalCardCommittedException extends \RuntimeException
{
    public function __construct(
        public readonly Uuid $cardId,
        \Throwable $previous,
    ) {
        parent::__construct('The card was created, and a step after the commit failed.', previous: $previous);
    }
}
