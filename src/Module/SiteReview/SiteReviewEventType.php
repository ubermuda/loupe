<?php

declare(strict_types=1);

namespace App\Module\SiteReview;

/** The outbox event types this module produces. */
final class SiteReviewEventType
{
    public const string SUBMITTED = 'site_review.submitted';

    private function __construct()
    {
    }
}
