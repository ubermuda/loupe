<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A single widget comment, saved immediately on composer save. A comment with
 * no anchor at all is an unanchored page note.
 *
 * `selector` and `text` are the pre-anchors request body. The widget script
 * carries no version in its URL, so a browser can hold a cached copy for a long
 * time and still post that shape. The controller maps it to one anchor.
 */
final class AddCommentRequest
{
    /**
     * @param list<SiteReviewAnchorInput> $anchors
     */
    public function __construct(
        #[Assert\Length(max: 10000)]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $body = null,

        #[Assert\Length(max: 2000)]
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Url(protocols: ['http', 'https'], requireTld: false)]
        public ?string $url = null,

        #[Assert\All([new Assert\Type(SiteReviewAnchorInput::class)])]
        #[Assert\Count(max: 10)]
        #[Assert\Valid]
        public array $anchors = [],

        #[Assert\Length(max: 2000)]
        public string $selector = '',

        #[Assert\Length(max: 2000)]
        public string $text = '',
    ) {
    }
}
