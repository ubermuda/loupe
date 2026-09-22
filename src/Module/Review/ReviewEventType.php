<?php

declare(strict_types=1);

namespace App\Module\Review;

/**
 * The outbox event types a review produces. The Board module writes them,
 * because the payload names the cards the document hangs off and no module
 * outside Board may read a card. The names live here, with the domain they
 * describe.
 */
final class ReviewEventType
{
    public const string REVIEW_SUBMITTED = 'document.review_submitted';

    private function __construct()
    {
    }
}
