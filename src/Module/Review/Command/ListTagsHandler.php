<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Repository\TagRepository;

final readonly class ListTagsHandler
{
    public function __construct(
        private TagRepository $tags,
    ) {
    }

    public function __invoke(ListTagsCommand $command): ListTagsView
    {
        return new ListTagsView($this->tags->findByProjectWithDocumentCounts($command->project));
    }
}
