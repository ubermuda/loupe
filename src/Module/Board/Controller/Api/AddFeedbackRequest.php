<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use App\Module\SiteReview\Controller\Api\AddCommentRequest;
use Symfony\Component\Validator\Constraints as Assert;

/** A widget note plus the card it goes to. */
final class AddFeedbackRequest extends AddCommentRequest
{
    #[Assert\NotNull]
    #[Assert\Valid]
    public ?FeedbackTargetInput $target = null;
}
