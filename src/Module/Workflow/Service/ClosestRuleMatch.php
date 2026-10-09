<?php

declare(strict_types=1);

namespace App\Module\Workflow\Service;

use App\Module\Workflow\Contract\Facts;
use App\Module\Workflow\Template\Rule;

final readonly class ClosestRuleMatch
{
    public function __construct(
        public Rule $rule,
        public Facts $facts,
    ) {
    }
}
