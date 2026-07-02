<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A single widget comment, saved immediately on composer save.
 * selector/text are optional — an unanchored (general) comment carries neither.
 */
final class AddCommentRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $body = null,
        public string $selector = '',
        public string $text = '',

        #[Assert\NotBlank]
        public ?string $url = null,
    ) {
    }
}
