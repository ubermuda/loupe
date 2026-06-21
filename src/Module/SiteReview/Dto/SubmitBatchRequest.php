<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The site-review submit-batch payload, deserialized from the JSON request body
 * via #[MapRequestPayload]. Not form-bound.
 */
final class SubmitBatchRequest
{
    /**
     * @param list<SiteReviewCommentRequest> $comments
     */
    public function __construct(
        #[Assert\Count(min: 1)]
        #[Assert\Valid]
        public array $comments = [],
    ) {
    }
}
