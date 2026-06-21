<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One comment in a site-review batch, deserialized from the JSON request body
 * via #[MapRequestPayload]. Not form-bound.
 */
final class SiteReviewCommentRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $body = null,
        // selector/text are optional — an unanchored comment carries neither.
        // The SiteReviewComment entity stores '' (not null) for both.
        public string $selector = '',
        public string $text = '',

        #[Assert\NotBlank]
        public ?string $url = null,
    ) {
    }
}
