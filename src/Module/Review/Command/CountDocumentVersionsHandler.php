<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\DocumentVersionRepository;

/** How many of the named version numbers a document has. */
final readonly class CountDocumentVersionsHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
    ) {
    }

    public function __invoke(CountDocumentVersionsCommand $command): int
    {
        return $this->documentVersions->countByNumbers($command->document, $command->versionNumbers);
    }
}
