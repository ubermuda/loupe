<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** Why the engine cannot read a source of facts, so a rule that reads it waits. */
final readonly class Unreadable
{
    /** @param string $source the translation key of the source label, or the key of the missing condition */
    public function __construct(
        public UnreadableKind $kind,
        public string $source,
        public ?\Throwable $cause = null,
    ) {
    }
}
