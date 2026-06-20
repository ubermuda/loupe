<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Form;

use Symfony\Component\Validator\Constraints as Assert;

class SubmitBatchRequest
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
