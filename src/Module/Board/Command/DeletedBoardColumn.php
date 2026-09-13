<?php

declare(strict_types=1);

namespace App\Module\Board\Command;

/** What a column delete removed, and where its cards went. */
final readonly class DeletedBoardColumn
{
    /**
     * @param list<string> $movedCardIds
     */
    public function __construct(
        public string $columnId,
        public string $projectId,
        public string $slug,
        public ?string $targetSlug,
        public array $movedCardIds,
    ) {
    }
}
