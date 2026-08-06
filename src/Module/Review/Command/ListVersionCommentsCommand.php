<?php

declare(strict_types=1);

namespace App\Module\Review\Command;

use App\Module\Review\Entity\DocumentVersion;

final readonly class ListVersionCommentsCommand
{
    public function __construct(
        public DocumentVersion $version,
    ) {
    }
}
