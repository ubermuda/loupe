<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One comment line-item inside a SubmitBatchRequest. Not a request of its own —
 * it exists so the Serializer hydrates each `comments` array element into a typed
 * object and #[Assert\Valid] can cascade per-item constraints.
 */
final class SiteReviewCommentInput
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
