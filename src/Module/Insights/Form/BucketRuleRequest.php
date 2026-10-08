<?php

declare(strict_types=1);

namespace App\Module\Insights\Form;

class BucketRuleRequest
{
    public function __construct(
        public ?string $pattern = null,
        public ?string $bucket = null,
    ) {
    }
}
