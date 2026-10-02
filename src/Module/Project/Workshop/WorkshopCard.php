<?php

declare(strict_types=1);

namespace App\Module\Project\Workshop;

final readonly class WorkshopCard
{
    public function __construct(
        public int $number,
        public string $title,
        public string $url,
        public string $column,
        /** The column's label colour, one of the .lp-tag tone modifiers. */
        public string $columnTone,
        /** The translation key of the open run's state. */
        public string $stateLabel,
        /** The .lp-status-chip modifier of the open run's state. */
        public string $stateTone,
        /** The rule name of a worker run, blank when the rule has none. Null for an interactive session. */
        public ?string $kind,
        /** When the open run started, or when it began to wait for a start. */
        public \DateTimeImmutable $since,
        /** The open run is a command that a rule runs with no agent. */
        public bool $command = false,
    ) {
    }
}
