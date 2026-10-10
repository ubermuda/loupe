<?php

declare(strict_types=1);

namespace App\Module\Board\Service;

use App\Module\Board\Entity\Card;

/** Posts the agent reviews of a card as checks on its pull requests. The AgentReview module implements it. */
interface AgentReviewCheck
{
    /** The result holds the cause of the first failure, and whether a review row changed. */
    public function publish(Card $card): SiteReviewWriteResult;
}
