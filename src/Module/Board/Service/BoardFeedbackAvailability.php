<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\SiteReview\Context\FeedbackAvailabilityInterface;

/** A widget note becomes card feedback, so it needs the board switched on. */
final readonly class BoardFeedbackAvailability implements FeedbackAvailabilityInterface
{
    public function __construct(
        private BoardAvailability $board,
    ) {
    }

    #[\Override]
    public function isAvailable(): bool
    {
        return $this->board->isEnabled();
    }
}
