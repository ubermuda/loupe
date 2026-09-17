<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Form;

use App\Module\SiteReview\Entity\SiteReviewReply;
use Symfony\Component\Validator\Constraints as Assert;

final class ReplyToSiteReviewCommentRequest
{
    public function __construct(
        #[Assert\Length(max: SiteReviewReply::MAX_BODY_LENGTH)]
        #[Assert\NotBlank]
        public ?string $body = null,

        #[Assert\NotBlank]
        #[Assert\Uuid]
        public ?string $submissionId = null,
    ) {
    }
}
