<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

final class UpdateCommentRequest
{
    public function __construct(
        #[Assert\Length(max: 10000)]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $body = null,
    ) {
    }
}
