<?php

declare(strict_types=1);

namespace App\Module\Project\Workshop;

/** The readiness guide the Workshop shows until the owner hides it. */
final readonly class WorkshopReadiness
{
    public function __construct(
        /** @var list<WorkshopReadinessRow> */
        public array $rows,
        /** The card of the latest discovery run. Its open run does not end the quiet Workshop. */
        public ?int $discoveryCardNumber = null,
    ) {
    }

    public function row(string $key): ?WorkshopReadinessRow
    {
        return array_find($this->rows, static fn (WorkshopReadinessRow $row): bool => $key === $row->key);
    }

    public function doneCount(): int
    {
        return \count(array_filter($this->rows, static fn (WorkshopReadinessRow $row): bool => $row->done));
    }

    public function total(): int
    {
        return \count($this->rows);
    }
}
