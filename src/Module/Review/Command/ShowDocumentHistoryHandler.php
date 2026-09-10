<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\DocumentVersionRepository;

final readonly class ShowDocumentHistoryHandler
{
    public function __construct(
        private DocumentVersionRepository $documentVersions,
    ) {
    }

    public function __invoke(ShowDocumentHistoryCommand $command): ShowDocumentHistoryView
    {
        return new ShowDocumentHistoryView(
            document: $command->document,
            versions: $this->documentVersions->findAllMetaByDocument($command->document),
        );
    }
}
