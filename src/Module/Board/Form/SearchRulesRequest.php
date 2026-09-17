<?php

declare(strict_types=1);

namespace App\Module\Board\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class SearchRulesRequest
{
    public function __construct(
        #[Assert\Length(max: 200)]
        public ?string $search = null,
    ) {
    }
}
