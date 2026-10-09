<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** One action of a child choice, as the workflow template declares it. */
final readonly class ChildChoiceStep
{
    /** @param array<string, int|string> $params */
    public function __construct(
        public string $key,
        public array $params,
        /** The column of the `to` slot of the action, or null when the action has none or the slot has no column. */
        public ?ColumnRef $to,
        /** The column of the `from` slot of the action, or null when the action has none or the slot has no column. */
        public ?ColumnRef $from = null,
    ) {
    }
}
