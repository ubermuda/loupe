<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Form;

use Symfony\Component\Validator\Constraints as Assert;

class SiteReviewCommentRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public ?string $body = null,
        // selector/text are optional — an unanchored comment carries neither.
        // The SiteReviewComment entity stores '' (not null) for both.
        public ?string $selector = null,
        public ?string $text = null,

        #[Assert\NotBlank]
        public ?string $url = null,
    ) {
    }
}
