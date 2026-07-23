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
        #[Assert\Length(max: 10000)]
        public ?string $body = null,

        #[Assert\Length(max: 2000)]
        public string $selector = '',

        #[Assert\Length(max: 2000)]
        public string $text = '',

        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 2000)]
        #[Assert\Url(protocols: ['http', 'https'], requireTld: false)]
        public ?string $url = null,
    ) {
    }
}
