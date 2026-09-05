<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One element of an incoming comment's anchors[] list. The quote fields carry a
 * run of text inside the element; the widget does not send them yet.
 */
final class SiteReviewAnchorInput
{
    public function __construct(
        #[Assert\Length(max: 2000)]
        #[Assert\NotBlank]
        public ?string $selector = null,

        #[Assert\Length(max: 2000)]
        public string $text = '',

        #[Assert\Length(max: 2000)]
        public ?string $quote = null,

        #[Assert\Length(max: 2000)]
        public ?string $quotePrefix = null,

        #[Assert\Length(max: 2000)]
        public ?string $quoteSuffix = null,
    ) {
    }
}
