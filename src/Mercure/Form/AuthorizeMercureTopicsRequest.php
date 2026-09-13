<?php

declare(strict_types=1);

namespace App\Mercure\Form;

use Symfony\Component\Validator\Constraints as Assert;

class AuthorizeMercureTopicsRequest
{
    /** @param list<string> $topics */
    public function __construct(
        #[Assert\All([new Assert\NotBlank(), new Assert\Length(max: 2000)])]
        #[Assert\Count(max: 50)]
        public array $topics = [],
    ) {
    }
}
