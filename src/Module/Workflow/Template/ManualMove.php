<?php

declare(strict_types=1);

namespace App\Module\Workflow\Template;

/** A move a person may make on a managed card. Each end is a slot key, '@backlog', '@terminal' or '*' for any column. */
final readonly class ManualMove
{
    public function __construct(
        public string $from,
        public string $to,
    ) {
    }
}
