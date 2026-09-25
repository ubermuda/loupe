<?php

declare(strict_types=1);

namespace App\Module\Board\Controller\Api;

use Symfony\Component\Validator\Constraints as Assert;

/** The card a note creates. The note gives it its title. */
final class NewCardInput
{
    public function __construct(
        /** An epic of the project, which the new card goes under. */
        #[Assert\Uuid]
        public ?string $parentCardId = null,
    ) {
    }
}
