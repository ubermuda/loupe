<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Context;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Says whether a widget note has somewhere to go.
 *
 * The module that stores a note's card implements this, so SiteReview reads
 * the answer without knowing that module. With no implementation the answer
 * is no, and the widget refuses notes rather than losing them.
 */
#[AutoconfigureTag('app.site_review_feedback_availability')]
interface FeedbackAvailabilityInterface
{
    public function isAvailable(): bool;
}
