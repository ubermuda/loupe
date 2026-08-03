<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A strike carries the anchor and nothing else — there is no field for a human to
 * fill in, which is the whole point of the gesture.
 */
class StrikePassageRequest
{
    public function __construct(
        // Length caps mirror AddCommentRequest: prefix/suffix are VARCHAR(255)
        // columns on the Anchor value object, so an oversized hand-crafted POST
        // must fail validation rather than 500 on a driver exception.
        #[Assert\Length(max: 2000)]
        #[Assert\NotBlank]
        public ?string $quote = null,

        #[Assert\Length(max: 255)]
        public ?string $prefix = null,

        #[Assert\Length(max: 255)]
        public ?string $suffix = null,
    ) {
    }
}
