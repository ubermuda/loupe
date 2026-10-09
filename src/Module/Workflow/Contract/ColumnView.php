<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** A board column as the workflow reads it. */
final readonly class ColumnView
{
    public function __construct(
        public Uuid $id,
        public Uuid $projectId,
        public string $label,
        public string $slug,
        public int $position,
        public bool $backlog,
        public bool $terminal,
        public LabelTone $tone,
    ) {
    }

    public function ref(): ColumnRef
    {
        return new ColumnRef($this->id, $this->backlog, $this->terminal);
    }
}
