<?php

declare(strict_types=1);

namespace App\Module\SiteReview\Form;

use Symfony\Component\Validator\Constraints as Assert;

class CreateSiteRequest
{
    public function __construct(
        #[Assert\Length(max: 100)]
        #[Assert\NotBlank(normalizer: 'trim')]
        public ?string $name = null,
    ) {
    }
}
