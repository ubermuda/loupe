<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\CommentRepository;
use App\Module\Review\Repository\DocumentVersionRepository;

final readonly class ListLatestCommentsHandler
{
    public function __construct(
        private CommentRepository $comments,
        private DocumentVersionRepository $documentVersions,
    ) {
    }

    public function __invoke(ListLatestCommentsCommand $command): ListLatestCommentsView
    {
        return new ListLatestCommentsView(
            comments: $this->comments->findByVersion(
                $this->documentVersions->findLatest($command->document),
            ),
        );
    }
}
