<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use Symfony\Component\Validator\Constraints as Assert;

class AddCommentRequest
{
    public function __construct(
        // Captured client-side from the document's textContent, and empty for an
        // untargeted comment — so none are required. The lengths guard a
        // hand-crafted POST: prefix/suffix are VARCHAR(255), which would
        // otherwise 500 on a driver exception rather than fail validation.
        #[Assert\Length(max: 2000)]
        public ?string $quote = null,

        #[Assert\Length(max: 255)]
        public ?string $prefix = null,

        #[Assert\Length(max: 255)]
        public ?string $suffix = null,

        #[Assert\NotBlank]
        public ?string $body = null,
    ) {
    }
}
