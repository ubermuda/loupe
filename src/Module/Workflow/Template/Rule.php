<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

use App\Module\Workflow\Expression\Expression;

final readonly class Rule
{
    /** @param ?string $slot a slot key, '@backlog', '@terminal', or null for a rule that applies in every column */
    public function __construct(
        public string $id,
        public ?string $slot,
        public Expression $when,
        public ActionCall $then,
    ) {
    }
}
