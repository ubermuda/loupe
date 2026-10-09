<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** A board column as the workflow sees it: its identity and the two flags that give it a fixed meaning. */
final readonly class ColumnRef
{
    public function __construct(
        public Uuid $id,
        public bool $backlog,
        public bool $terminal,
    ) {
    }
}
