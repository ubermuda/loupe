<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

use Symfony\Component\Uid\Uuid;

/** What the workflow reads about a card when it decides on a move. */
final readonly class CardSnapshot
{
    public function __construct(
        public Uuid $id,
        public Uuid $projectId,
        public int $number,
        public string $type,
        public ColumnRef $column,
        public ?Uuid $parentId,
        public ?int $parentNumber,
        public ?ColumnRef $parentColumn,
    ) {
    }
}
