<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A suggested rewording: the anchored passage plus what it should become.
 *
 * `body` is the rationale and stays optional — the replacement already says what
 * the reviewer wants, and demanding prose on top of it is the friction this
 * feature exists to remove.
 */
class SuggestRewordingRequest
{
    public function __construct(
        #[Assert\Length(max: 2000)]
        #[Assert\NotBlank]
        public ?string $quote = null,

        #[Assert\Length(max: 255)]
        public ?string $prefix = null,

        #[Assert\Length(max: 255)]
        public ?string $suffix = null,

        // NotBlank, not merely NotNull: an empty replacement is a strike, and a
        // strike must be reached through its own one-gesture action rather than by
        // opening this form and leaving the field alone.
        #[Assert\Length(max: 4000)]
        #[Assert\NotBlank]
        public ?string $replacement = null,

        #[Assert\Length(max: 4000)]
        public ?string $body = null,
    ) {
    }
}
