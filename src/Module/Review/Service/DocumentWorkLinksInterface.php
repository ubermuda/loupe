<?php

declare(strict_types=1);

namespace App\Module\Review\Service;

use App\Module\Project\Entity\Project;
use App\Module\Review\Entity\Document;

interface DocumentWorkLinksInterface
{
    public function isEnabled(): bool;

    /** @return array<string, string> */
    public function choices(Project $project, ?Document $document): array;

    /** @return list<string> */
    public function selectedIds(Document $document): array;

    /** @param list<string> $ids */
    public function validate(Document $document, array $ids): void;

    /**
     * The caller holds the project row lock and owns the transaction and flush.
     *
     * @param list<string> $ids
     */
    public function synchronize(Document $document, array $ids): void;
}
