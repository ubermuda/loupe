<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use Symfony\Component\Validator\Constraints as Assert;

class AddCommentRequest
{
    public function __construct(
        // The verbatim selected text and its surrounding context, captured
        // client-side from the document's textContent (same basis as
        // DocumentVersion::plainText()). All three are empty for an untargeted
        // comment, so none are required.
        public ?string $quote = null,
        public ?string $prefix = null,
        public ?string $suffix = null,

        #[Assert\NotBlank]
        public ?string $body = null,
    ) {
    }
}
