<?php

declare(strict_types=1);

namespace App\Module\OAuth\Form;

class ConsentRequest
{
    public function __construct(
        public ?string $project = null,
    ) {
    }
}
