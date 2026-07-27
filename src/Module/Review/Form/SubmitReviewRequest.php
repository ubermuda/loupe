<?php

declare(strict_types=1);

namespace App\Module\Review\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * `verdict` carries the raw submitted value (one of the two verdict-bar
 * button values) — it is intentionally not typed as the `Verdict` backed
 * enum here. Parsing it into a `Verdict` is domain logic and lives in
 * `SubmitReviewHandler`, which throws `DomainErrors` on an unrecognised
 * value; this DTO only guards against a missing/blank submission.
 */
final class SubmitReviewRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public ?string $verdict = null,
    ) {
    }
}
