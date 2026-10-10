<?php

declare(strict_types=1);

namespace App\Module\Workflow\Contract;

/** The document tags that the rules of each column read. */
final readonly class StageDocumentTags
{
    /**
     * @param array<string, list<string>> $byColumn the tags of each linked column, keyed by its RFC 4122 id
     * @param list<string>                $backlog  the tags of the Backlog
     * @param list<string>                $unlinked the tags of a column that no slot links
     */
    public function __construct(
        public array $byColumn,
        public array $backlog,
        public array $unlinked,
    ) {
    }

    /** @return list<string> */
    public function forColumn(string $columnId, bool $backlog): array
    {
        return $backlog ? $this->backlog : $this->byColumn[$columnId] ?? $this->unlinked;
    }
}
