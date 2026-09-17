<?php

declare(strict_types=1);

namespace App\Search\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class SearchRequest
{
    public function __construct(
        #[Assert\Length(max: 200)]
        public string $query = '',

        #[Assert\Positive]
        public int $page = 1,
    ) {
    }
}
