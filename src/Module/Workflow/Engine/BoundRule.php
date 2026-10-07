<?php

declare(strict_types=1);

namespace App\Module\Workflow\Engine;

use App\Module\Workflow\Contract\Facts;
use Symfony\Component\Uid\Uuid;

/** One rule read against the pull request it acts on. */
final readonly class BoundRule
{
    /**
     * @param ?Uuid $subject the id of the pull request the facts read as the one the card acts on
     * @param bool  $binds   the rule reads the pull request, so it chose its subject among the open ones
     */
    public function __construct(
        public Facts $facts,
        public ?Uuid $subject,
        public bool $truth,
        public bool $binds,
    ) {
    }
}
